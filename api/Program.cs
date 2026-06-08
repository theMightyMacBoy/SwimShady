using System.Text;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.AspNetCore.HttpOverrides;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Tokens;
using SwimShady.Api.Data;
using SwimShady.Api.Middleware;
using SwimShady.Api.Models;
using SwimShady.Api.Services;

var builder = WebApplication.CreateBuilder(args);
var cfg = builder.Configuration;

builder.Services.AddDbContext<AppDbContext>(opt =>
    opt.UseSqlServer(cfg.GetConnectionString("Default")
        ?? throw new InvalidOperationException("Missing ConnectionStrings:Default")));

builder.Services.Configure<AppSettings>(cfg.GetSection("App"));
builder.Services.AddSingleton<IPasswordHasher<User>, PasswordHasher<User>>();
builder.Services.AddSingleton<JwtTokenService>();
builder.Services.AddScoped<IpSecurityService>();

// Azure App Service terminates the edge and forwards the client IP in X-Forwarded-For.
builder.Services.Configure<ForwardedHeadersOptions>(o =>
{
    o.ForwardedHeaders = ForwardedHeaders.XForwardedFor | ForwardedHeaders.XForwardedProto;
    o.KnownNetworks.Clear();
    o.KnownProxies.Clear();
});

var jwtKey = cfg["Jwt:Key"] ?? throw new InvalidOperationException("Missing Jwt:Key");
builder.Services.AddAuthentication(JwtBearerDefaults.AuthenticationScheme)
    .AddJwtBearer(o =>
    {
        o.TokenValidationParameters = new TokenValidationParameters
        {
            ValidateIssuer = true,
            ValidIssuer = cfg["Jwt:Issuer"] ?? "SwimShady",
            ValidateAudience = true,
            ValidAudience = cfg["Jwt:Audience"] ?? "SwimShady",
            ValidateIssuerSigningKey = true,
            IssuerSigningKey = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(jwtKey)),
            ValidateLifetime = true,
            ClockSkew = TimeSpan.FromMinutes(1),
        };
    });
builder.Services.AddAuthorization();
builder.Services.AddControllers();
builder.Services.AddHostedService<PruneService>();
builder.Services.AddHostedService<DemoSimulatorService>();

var app = builder.Build();

// Create schema if needed and seed an initial admin (admin / admin).
// CHANGE the admin password immediately after first login.
using (var scope = app.Services.CreateScope())
{
    var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();
    db.Database.EnsureCreated();
    // Additive columns/tables that EnsureCreated() won't apply to an existing database.
    db.Database.ExecuteSqlRaw(
        "IF COL_LENGTH('DeviceStates','HeaterStatus') IS NULL ALTER TABLE DeviceStates ADD HeaterStatus NVARCHAR(32) NULL;");
    db.Database.ExecuteSqlRaw(
        "IF COL_LENGTH('Devices','FirmwareVersion') IS NULL ALTER TABLE Devices ADD FirmwareVersion NVARCHAR(64) NULL;");
    db.Database.ExecuteSqlRaw(@"
IF OBJECT_ID('Firmware','U') IS NULL
CREATE TABLE Firmware (
    Id INT IDENTITY(1,1) PRIMARY KEY,
    Version NVARCHAR(64) NOT NULL,
    Channel NVARCHAR(16) NOT NULL DEFAULT 'stable',
    Md5 NVARCHAR(32) NOT NULL,
    Size INT NOT NULL,
    Data VARBINARY(MAX) NOT NULL,
    CreatedAt DATETIME2 NOT NULL,
    Notes NVARCHAR(255) NULL
);");
    db.Database.ExecuteSqlRaw(
        "IF COL_LENGTH('Devices','Channel') IS NULL ALTER TABLE Devices ADD Channel NVARCHAR(16) NOT NULL DEFAULT 'stable';");
    db.Database.ExecuteSqlRaw(
        "IF COL_LENGTH('Firmware','Channel') IS NULL ALTER TABLE Firmware ADD Channel NVARCHAR(16) NOT NULL DEFAULT 'stable';");
    db.Database.ExecuteSqlRaw(
        "IF COL_LENGTH('Devices','IsDemo') IS NULL ALTER TABLE Devices ADD IsDemo BIT NOT NULL DEFAULT 0;");

    // IP security tables (EnsureCreated() won't add tables to an existing database).
    db.Database.ExecuteSqlRaw(@"
IF OBJECT_ID('LoginAttempts','U') IS NULL
CREATE TABLE LoginAttempts (
    Id BIGINT IDENTITY(1,1) PRIMARY KEY,
    Ip NVARCHAR(45) NOT NULL,
    Username NVARCHAR(64) NULL,
    Success BIT NOT NULL,
    CreatedAt DATETIME2 NOT NULL
);");
    db.Database.ExecuteSqlRaw(@"
IF OBJECT_ID('ApiRequests','U') IS NULL
CREATE TABLE ApiRequests (
    Id BIGINT IDENTITY(1,1) PRIMARY KEY,
    Ip NVARCHAR(45) NOT NULL,
    Method NVARCHAR(8) NOT NULL,
    Path NVARCHAR(256) NOT NULL,
    StatusCode INT NOT NULL,
    UserId INT NULL,
    CreatedAt DATETIME2 NOT NULL
);");
    db.Database.ExecuteSqlRaw(@"
IF OBJECT_ID('IpRules','U') IS NULL
CREATE TABLE IpRules (
    Id INT IDENTITY(1,1) PRIMARY KEY,
    Ip NVARCHAR(45) NOT NULL,
    Kind INT NOT NULL,
    Source INT NOT NULL,
    Reason NVARCHAR(255) NULL,
    ExpiresAt DATETIME2 NULL,
    CreatedAt DATETIME2 NOT NULL,
    CreatedBy INT NULL
);");
    // Index names match EF's conventions, so these are no-ops on a fresh EnsureCreated() DB.
    db.Database.ExecuteSqlRaw(
        "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_LoginAttempts_Ip_CreatedAt') CREATE INDEX IX_LoginAttempts_Ip_CreatedAt ON LoginAttempts (Ip, CreatedAt);");
    db.Database.ExecuteSqlRaw(
        "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_ApiRequests_Ip_CreatedAt') CREATE INDEX IX_ApiRequests_Ip_CreatedAt ON ApiRequests (Ip, CreatedAt);");
    db.Database.ExecuteSqlRaw(
        "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_IpRules_Ip') CREATE INDEX IX_IpRules_Ip ON IpRules (Ip);");

    if (!db.Users.Any())
    {
        var hasher = scope.ServiceProvider.GetRequiredService<IPasswordHasher<User>>();
        var admin = new User { Username = "admin", Role = Roles.Admin };
        admin.PasswordHash = hasher.HashPassword(admin, "admin");
        db.Users.Add(admin);
        db.SaveChanges();
    }

    // Seed the public demo: a simulated device + a "demo"/"demo" user scoped to ONLY it.
    // Role is "standard" so the demo can drive the controls; it still can't see real
    // devices or reach admin endpoints. The DemoSimulatorService animates its state.
    {
        var hasher = scope.ServiceProvider.GetRequiredService<IPasswordHasher<User>>();
        var demoDevice = db.Devices.FirstOrDefault(d => d.IsDemo);
        if (demoDevice is null)
        {
            demoDevice = new Device
            {
                Uid = "demo-pool",
                Name = "Demo Pool",
                IsDemo = true,
                TokenHash = Tokens.Hash(Tokens.NewDeviceToken()), // unused; the simulator drives it
                LastSeenAt = DateTime.UtcNow,
                FirmwareVersion = "2026.06.01",   // static, cosmetic — demo never heartbeats/OTAs
                Channel = Channels.Stable,
                State = new DeviceState
                {
                    WaterTemp = 75, TargetTemp = 82, SystemOn = false, PumpOn = false, HeaterOn = false,
                    HeaterStatus = "OFF", PumpStatus = "OFF", CooldownRemaining = "--:--", ReportedAt = DateTime.UtcNow,
                },
            };
            db.Devices.Add(demoDevice);
            db.SaveChanges();
        }
        else if (demoDevice.FirmwareVersion != "2026.06.01" || demoDevice.Channel != Channels.Stable)
        {
            // Keep the demo's displayed firmware/branch stable even if it was seeded earlier without one.
            demoDevice.FirmwareVersion = "2026.06.01";
            demoDevice.Channel = Channels.Stable;
            db.SaveChanges();
        }

        var demoUser = db.Users.FirstOrDefault(u => u.Username == "demo");
        if (demoUser is null)
        {
            demoUser = new User { Username = "demo", Role = Roles.Standard };
            demoUser.PasswordHash = hasher.HashPassword(demoUser, "demo");
            db.Users.Add(demoUser);
            db.SaveChanges();
        }

        if (!db.UserDevices.Any(ud => ud.UserId == demoUser.Id && ud.DeviceId == demoDevice.Id))
        {
            db.UserDevices.Add(new UserDevice { UserId = demoUser.Id, DeviceId = demoDevice.Id });
            db.SaveChanges();
        }
    }

    // Permanent whitelist from config (App:WhitelistIps, comma-separated). Real IPs live in
    // git-ignored config / Azure app settings, never in committed source.
    var appOpt = scope.ServiceProvider.GetRequiredService<IOptions<AppSettings>>().Value;
    if (!string.IsNullOrWhiteSpace(appOpt.WhitelistIps))
    {
        foreach (var ip in appOpt.WhitelistIps.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            if (!db.IpRules.Any(r => r.Ip == ip && r.Kind == IpRuleKind.Allow))
                db.IpRules.Add(new IpRule { Ip = ip, Kind = IpRuleKind.Allow, Source = IpRuleSource.Manual, Reason = "configured whitelist" });
        }
        db.SaveChanges();
    }
}

app.UseForwardedHeaders();                  // resolve real client IP first
app.UseMiddleware<IpGuardMiddleware>();     // block banned IPs + log every /api request
app.UseAuthentication();
app.UseAuthorization();
app.MapControllers();
app.MapGet("/", () => Results.Ok(new { service = "SwimShady.Api", status = "ok" }));

app.Run();
