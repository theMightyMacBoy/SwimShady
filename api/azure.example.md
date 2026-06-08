# Azure deployment — Swim Shady API (TEMPLATE)

Copy this to `azure.local.md` (git-ignored) and fill in your real values. This shows
everything you need to provision and configure to deploy the API. See `README.md`
for the full step-by-step CLI commands.

## Subscription
- Name: <your subscription name>
- Subscription ID: <guid>
- Tenant: <tenant name>

## Resource group
- Name: <e.g. <your-rg>>
- RG metadata location: <e.g. eastus>
- Resource location (SQL + App Service): <region that allows new SQL servers, e.g. westus3>

## Azure SQL Database
- Server: <unique server name>
- Server FQDN: <server>.database.windows.net
- Database: <e.g. swimshady>   (GeneralPurpose serverless Gen5 2-vCore, FREE offer)
- Admin login: <sql admin user>
- Admin password: <sql admin password>
- Firewall rule: AllowAzure = 0.0.0.0 – 0.0.0.0 (lets Azure services connect)

### Connection string  (App Service → Connection strings → name "Default", type SQLAzure)
```
Server=tcp:<server>.database.windows.net,1433;Initial Catalog=<db>;User ID=<admin>;Password=<password>;Encrypt=True;TrustServerCertificate=False;Connection Timeout=60;
```

## App Service
- Plan: <e.g. swimshady-plan>   (F1 free, Linux)
- Web app: <unique app name>
- URL: https://<app>.azurewebsites.net
- App setting: Jwt__Key = <a long random secret, e.g. `openssl rand -hex 32`>

## App login (seeded on first run)
- username: admin
- password: admin   ← change immediately via POST /api/account/password

## Devices (token shown once on POST /api/devices)
- uid: <device uid>   name: <display name>   token: <device token → ESP32/secrets.yaml swimshady_token>
- heartbeat URL for firmware: https://<app>.azurewebsites.net/api/device/heartbeat

## Re-set CLI variables in a new terminal
```bash
RG=<rg>
LOC=<region>
SQLSRV=<sql server>
SQLDB=<db>
SQLADMIN=<admin>
SQLPASS='<password>'
PLAN=<plan>
APP=<web app>
```
