param(
	[string]$WpCliService = "wpcli",
	[string]$ComposeFile = ""
)

$ErrorActionPreference = "Stop"

if ( [string]::IsNullOrWhiteSpace( $ComposeFile ) ) {
	$ComposeFile = Join-Path $PSScriptRoot "docker-compose.yml"
}
if ( -not ( Test-Path $ComposeFile ) ) {
	throw "Docker compose file not found: $ComposeFile"
}

function Invoke-ComposeWpCli {
	param(
		[string[]]$CommandArgs
	)
	$command = @(
		"compose",
		"-f", $ComposeFile,
		"run",
		"--rm",
		"--user", "root",
		$WpCliService
	) + @("wp") + $CommandArgs + @("--allow-root")
	& docker @command
	if ( $LASTEXITCODE -ne 0 ) {
		throw "Classic Editor provisioning command failed: docker $($command -join ' ')"
	}
}

Write-Host "Provisioning Classic Editor plugin for deterministic E2E coverage..."
Invoke-ComposeWpCli -CommandArgs @("plugin", "install", "classic-editor", "--activate")
Invoke-ComposeWpCli -CommandArgs @("option", "update", "classic-editor-replace", "Classic")
Invoke-ComposeWpCli -CommandArgs @("option", "update", "classic-editor-allow-users", "0")

Write-Host "Classic Editor provisioning complete."
