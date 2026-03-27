param(
	[string]$ComposeFile = "",
	[string]$WpCliService = "wpcli",
	[string]$BaseUrl = "http://localhost:8888",
	[string]$AdminUser = "admin",
	[string]$AdminPass = "password",
	[string]$AdminEmail = "admin@example.com",
	[string]$SiteTitle = "Open Growth SEO",
	[string]$Theme = "twentytwentyfive",
	[string]$Plugins = "",
	[string]$EnableClassic = "0",
	[string]$EnableMultisite = "0"
)

$ErrorActionPreference = "Stop"

if ( [string]::IsNullOrWhiteSpace( $ComposeFile ) ) {
	$ComposeFile = Join-Path $PSScriptRoot "docker-compose.yml"
}
if ( -not ( Test-Path $ComposeFile ) ) {
	throw "Docker compose file not found: $ComposeFile"
}

function Invoke-Compose {
	param(
		[string[]]$ComposeArgs
	)
	$command = @("compose", "-f", $ComposeFile) + $ComposeArgs
	& docker @command
	if ( $LASTEXITCODE -ne 0 ) {
		throw "Docker compose command failed: docker $($command -join ' ')"
	}
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
		$WpCliService,
		"wp"
	) + $CommandArgs + @("--allow-root")
	& docker @command
	if ( $LASTEXITCODE -ne 0 ) {
		throw "WP-CLI command failed: docker $($command -join ' ')"
	}
}

function Test-ComposeWpCli {
	param(
		[string[]]$CommandArgs
	)
	$command = @(
		"compose",
		"-f", $ComposeFile,
		"run",
		"--rm",
		"--user", "root",
		$WpCliService,
		"wp"
	) + $CommandArgs + @("--allow-root")
	& docker @command | Out-Null
	return $LASTEXITCODE -eq 0
}

function Wait-ForWordPress {
	param(
		[string]$Url
	)
	for ( $attempt = 0; $attempt -lt 40; $attempt++ ) {
		try {
			Invoke-WebRequest -UseBasicParsing -Uri "$Url/wp-login.php" -TimeoutSec 5 | Out-Null
			return
		} catch {
			Start-Sleep -Seconds 3
		}
	}
	throw "WordPress did not become reachable at $Url"
}

Write-Host "Starting Docker runtime for E2E environment provisioning..."
Invoke-Compose -ComposeArgs @("up", "-d", "db", "wordpress")
Wait-ForWordPress -Url $BaseUrl

if ( -not ( Test-ComposeWpCli -CommandArgs @("core", "is-installed") ) ) {
	Write-Host "Installing WordPress for E2E runtime..."
	Invoke-ComposeWpCli -CommandArgs @(
		"core",
		"install",
		"--url=$BaseUrl",
		"--title=$SiteTitle",
		"--admin_user=$AdminUser",
		"--admin_password=$AdminPass",
		"--admin_email=$AdminEmail",
		"--skip-email"
	)
}

Write-Host "Activating plugin and permalink structure..."
Invoke-ComposeWpCli -CommandArgs @("plugin", "activate", "open-growth-seo/open-growth-seo.php")
Invoke-ComposeWpCli -CommandArgs @("rewrite", "structure", "/%postname%/")

$themeSlug = $Theme.Trim()
if ( -not [string]::IsNullOrWhiteSpace( $themeSlug ) ) {
	Write-Host "Installing theme: $themeSlug"
	if ( -not ( Test-ComposeWpCli -CommandArgs @("theme", "is-installed", $themeSlug) ) ) {
		Invoke-ComposeWpCli -CommandArgs @("theme", "install", $themeSlug)
	}
	Invoke-ComposeWpCli -CommandArgs @("theme", "activate", $themeSlug)
}

$pluginList = @()
if ( -not [string]::IsNullOrWhiteSpace( $Plugins ) ) {
	$pluginList = $Plugins.Split(",") | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne "" }
}
if ( "1" -eq $EnableClassic -and $pluginList -notcontains "classic-editor" ) {
	$pluginList += "classic-editor"
}
$pluginList = $pluginList | Select-Object -Unique

foreach ( $plugin in $pluginList ) {
	Write-Host "Installing plugin: $plugin"
	if ( -not ( Test-ComposeWpCli -CommandArgs @("plugin", "is-installed", $plugin) ) ) {
		Invoke-ComposeWpCli -CommandArgs @("plugin", "install", $plugin)
	}
	Invoke-ComposeWpCli -CommandArgs @("plugin", "activate", $plugin)
}

if ( "1" -eq $EnableClassic ) {
	Write-Host "Configuring Classic Editor defaults..."
	Invoke-ComposeWpCli -CommandArgs @("option", "update", "classic-editor-replace", "Classic")
	Invoke-ComposeWpCli -CommandArgs @("option", "update", "classic-editor-allow-users", "0")
}

if ( "1" -eq $EnableMultisite ) {
	$isMultisite = & docker compose -f $ComposeFile run --rm --user root $WpCliService wp eval "echo is_multisite() ? '1' : '0';" --allow-root
	if ( $LASTEXITCODE -ne 0 ) {
		throw "Failed to detect multisite state."
	}
	if ( ( $isMultisite | Out-String ).Trim() -ne "1" ) {
		Write-Host "Converting runtime to multisite..."
		Invoke-ComposeWpCli -CommandArgs @("core", "multisite-convert", "--title=$SiteTitle Network", "--base=/")
	}
}

Write-Host "E2E environment provisioning complete."
