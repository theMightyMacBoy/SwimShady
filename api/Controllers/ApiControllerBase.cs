using System.Security.Claims;
using Microsoft.AspNetCore.Mvc;
using SwimShady.Api.Models;

namespace SwimShady.Api.Controllers;

[ApiController]
public abstract class ApiControllerBase : ControllerBase
{
    protected int UserId => int.Parse(User.FindFirstValue(ClaimTypes.NameIdentifier) ?? "0");
    protected string Role => User.FindFirstValue(ClaimTypes.Role) ?? Roles.ReadOnly;
    protected bool IsAdmin => Role == Roles.Admin;

    protected ActionResult Forbidden(string message) =>
        StatusCode(StatusCodes.Status403Forbidden, new { error = message });
}
