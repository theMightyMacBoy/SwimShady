using System.Security.Cryptography;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using SwimShady.Api.Data;
using SwimShady.Api.Dtos;
using SwimShady.Api.Models;

namespace SwimShady.Api.Controllers;

[Route("api/firmware")]
[Authorize(Roles = Roles.Admin)]
public class FirmwareController : ApiControllerBase
{
    private readonly AppDbContext _db;
    public FirmwareController(AppDbContext db) => _db = db;

    [HttpGet]
    public async Task<ActionResult<IEnumerable<FirmwareDto>>> List()
    {
        var items = await _db.Firmware.OrderByDescending(f => f.Id)
            .Select(f => new FirmwareDto(f.Id, f.Version, f.Channel, f.Md5, f.Size, f.CreatedAt, f.Notes))
            .ToListAsync();
        return Ok(items);
    }

    [HttpPost]
    [RequestSizeLimit(16_000_000)]
    public async Task<ActionResult<FirmwareDto>> Upload([FromForm] string? version, [FromForm] string? channel, [FromForm] string? notes, IFormFile? file)
    {
        if (string.IsNullOrWhiteSpace(version))
            return BadRequest(new { error = "version is required" });
        var ch = string.IsNullOrWhiteSpace(channel) ? Channels.Stable : channel.Trim();
        if (!Channels.All.Contains(ch))
            return BadRequest(new { error = "invalid channel" });
        if (file is null || file.Length == 0)
            return BadRequest(new { error = "firmware file is required" });

        using var ms = new MemoryStream();
        await file.CopyToAsync(ms);
        var data = ms.ToArray();
        var md5 = Convert.ToHexString(MD5.HashData(data)).ToLowerInvariant();

        var fw = new Firmware
        {
            Version = version.Trim(),
            Channel = ch,
            Md5 = md5,
            Size = data.Length,
            Data = data,
            Notes = string.IsNullOrWhiteSpace(notes) ? null : notes.Trim(),
        };
        _db.Firmware.Add(fw);
        await _db.SaveChangesAsync();

        return Ok(new FirmwareDto(fw.Id, fw.Version, fw.Channel, fw.Md5, fw.Size, fw.CreatedAt, fw.Notes));
    }

    [HttpDelete("{id:int}")]
    public async Task<IActionResult> Delete(int id)
    {
        var fw = await _db.Firmware.FindAsync(id);
        if (fw is null) return NotFound();
        _db.Firmware.Remove(fw);
        await _db.SaveChangesAsync();
        return NoContent();
    }
}
