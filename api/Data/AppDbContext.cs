using Microsoft.EntityFrameworkCore;
using SwimShady.Api.Models;

namespace SwimShady.Api.Data;

public class AppDbContext : DbContext
{
    public AppDbContext(DbContextOptions<AppDbContext> options) : base(options) { }

    public DbSet<User> Users => Set<User>();
    public DbSet<Device> Devices => Set<Device>();
    public DbSet<UserDevice> UserDevices => Set<UserDevice>();
    public DbSet<DeviceState> DeviceStates => Set<DeviceState>();
    public DbSet<HistoryEntry> History => Set<HistoryEntry>();
    public DbSet<CommandQueueItem> Commands => Set<CommandQueueItem>();
    public DbSet<Firmware> Firmware => Set<Firmware>();
    public DbSet<LoginAttempt> LoginAttempts => Set<LoginAttempt>();
    public DbSet<ApiRequest> ApiRequests => Set<ApiRequest>();
    public DbSet<IpRule> IpRules => Set<IpRule>();

    protected override void OnModelCreating(ModelBuilder b)
    {
        b.Entity<User>(e =>
        {
            e.HasIndex(u => u.Username).IsUnique();
            e.Property(u => u.Username).HasMaxLength(64).IsRequired();
            e.Property(u => u.PasswordHash).HasMaxLength(255).IsRequired();
            e.Property(u => u.Role).HasMaxLength(16).IsRequired();
        });

        b.Entity<Device>(e =>
        {
            e.HasIndex(d => d.Uid).IsUnique();
            e.HasIndex(d => d.TokenHash).IsUnique();
            e.Property(d => d.Uid).HasMaxLength(64).IsRequired();
            e.Property(d => d.Name).HasMaxLength(96).IsRequired();
            e.Property(d => d.TokenHash).HasMaxLength(64).IsRequired();
            e.Property(d => d.FirmwareVersion).HasMaxLength(64);
            e.Property(d => d.Channel).HasMaxLength(16).IsRequired();
            e.HasOne(d => d.State).WithOne(s => s.Device)
                .HasForeignKey<DeviceState>(s => s.DeviceId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        b.Entity<UserDevice>(e =>
        {
            e.HasKey(ud => new { ud.UserId, ud.DeviceId });
            e.HasOne(ud => ud.User).WithMany(u => u.UserDevices)
                .HasForeignKey(ud => ud.UserId).OnDelete(DeleteBehavior.Cascade);
            e.HasOne(ud => ud.Device).WithMany(d => d.UserDevices)
                .HasForeignKey(ud => ud.DeviceId).OnDelete(DeleteBehavior.Cascade);
        });

        b.Entity<DeviceState>(e =>
        {
            e.HasKey(s => s.DeviceId);
            e.Property(s => s.HeaterStatus).HasMaxLength(32);
            e.Property(s => s.PumpStatus).HasMaxLength(16);
            e.Property(s => s.CooldownRemaining).HasMaxLength(8);
        });

        b.Entity<HistoryEntry>(e =>
        {
            e.HasIndex(h => new { h.DeviceId, h.RecordedAt });
            e.Property(h => h.PumpStatus).HasMaxLength(16);
        });

        b.Entity<CommandQueueItem>(e =>
        {
            e.HasIndex(c => new { c.DeviceId, c.Status });
            e.Property(c => c.Action).HasMaxLength(32).IsRequired();
            e.Property(c => c.Value).HasMaxLength(32);
        });

        b.Entity<Firmware>(e =>
        {
            e.HasIndex(f => f.Channel);
            e.Property(f => f.Version).HasMaxLength(64).IsRequired();
            e.Property(f => f.Channel).HasMaxLength(16).IsRequired();
            e.Property(f => f.Md5).HasMaxLength(32).IsRequired();
            e.Property(f => f.Notes).HasMaxLength(255);
        });

        b.Entity<LoginAttempt>(e =>
        {
            e.HasIndex(l => new { l.Ip, l.CreatedAt });
            e.Property(l => l.Ip).HasMaxLength(45).IsRequired();
            e.Property(l => l.Username).HasMaxLength(64);
        });

        b.Entity<ApiRequest>(e =>
        {
            e.HasIndex(a => new { a.Ip, a.CreatedAt });
            e.Property(a => a.Ip).HasMaxLength(45).IsRequired();
            e.Property(a => a.Method).HasMaxLength(8).IsRequired();
            e.Property(a => a.Path).HasMaxLength(256).IsRequired();
        });

        b.Entity<IpRule>(e =>
        {
            e.HasIndex(r => r.Ip);
            e.Property(r => r.Ip).HasMaxLength(45).IsRequired();
            e.Property(r => r.Reason).HasMaxLength(255);
        });
    }
}
