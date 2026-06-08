using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using SwimShady.Api.Data;
using SwimShady.Api.Models;

namespace SwimShady.Api.Services;

/// <summary>Deletes history and IP logs older than their retention windows, once a day while the app is awake.</summary>
public class PruneService : BackgroundService
{
    private readonly IServiceProvider _sp;
    private readonly AppSettings _opt;

    public PruneService(IServiceProvider sp, IOptions<AppSettings> opt)
    {
        _sp = sp;
        _opt = opt.Value;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var scope = _sp.CreateScope();
                var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();

                if (_opt.HistoryRetentionDays > 0)
                {
                    var cutoff = DateTime.UtcNow.AddDays(-_opt.HistoryRetentionDays);
                    await db.History.Where(h => h.RecordedAt < cutoff).ExecuteDeleteAsync(stoppingToken);
                }

                if (_opt.LogRetentionDays > 0)
                {
                    var logCutoff = DateTime.UtcNow.AddDays(-_opt.LogRetentionDays);
                    await db.LoginAttempts.Where(l => l.CreatedAt < logCutoff).ExecuteDeleteAsync(stoppingToken);
                    await db.ApiRequests.Where(a => a.CreatedAt < logCutoff).ExecuteDeleteAsync(stoppingToken);
                    // Expired auto-bans age out; manual rules (whitelist + permanent blocks) are kept forever.
                    await db.IpRules
                        .Where(r => r.Source == IpRuleSource.Auto && r.CreatedAt < logCutoff)
                        .ExecuteDeleteAsync(stoppingToken);
                }
            }
            catch
            {
                // best-effort; try again next cycle
            }

            await Task.Delay(TimeSpan.FromHours(24), stoppingToken);
        }
    }
}
