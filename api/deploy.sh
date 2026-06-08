#!/usr/bin/env bash
# Build + deploy SwimShady.Api to Azure App Service.
# Prereqs: az CLI (run `az login` once), .NET 8 SDK.
# Override names with env vars if needed:  RG=... APP=... ./deploy.sh
set -euo pipefail

RG="${RG:-your-rg}"
APP="${APP:-your-app}"

# Always run from this script's directory (the api/ project root).
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Building (dotnet publish)…"
rm -rf publish app.zip
dotnet publish -c Release -o ./publish

echo "==> Packaging…"
( cd publish && zip -qr ../app.zip . )

echo "==> Deploying to $APP (resource group $RG)…"
az webapp deploy -n "$APP" -g "$RG" --src-path app.zip --type zip

echo "==> Done: https://$APP.azurewebsites.net"
