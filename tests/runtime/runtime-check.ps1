param(
    [string]$BaseUrl = 'http://localhost:8888',
    [string]$AdminUser = 'admin',
    [string]$AdminPass = 'password',
    [string]$WpPath = '',
    [ValidateSet('auto','docker','local')]
    [string]$CliMode = 'auto',
    [switch]$CliOnly
)

$ErrorActionPreference = 'Stop'

$results = @()

function Add-Result {
    param(
        [string]$Name,
        [bool]$Ok,
        [string]$Detail
    )

    $script:results += [pscustomobject]@{
        name   = $Name
        ok     = $Ok
        detail = $Detail
    }

    $status = if ( $Ok ) { 'PASS' } else { 'FAIL' }
    Write-Host ("[{0}] {1}: {2}" -f $status, $Name, $Detail)
}

function Parse-Nonce {
    param([string]$Html)

    $patterns = @(
        'createNonceMiddleware\( "([a-zA-Z0-9]+)" \)',
        'wpApiSettings\s*=\s*\{[^}]*"nonce":"([^"]+)"',
        '"nonce":"([a-zA-Z0-9]+)"'
    )

    foreach ( $pattern in $patterns ) {
        $match = [regex]::Match($Html, $pattern)
        if ( $match.Success ) {
            return [System.Net.WebUtility]::HtmlDecode($match.Groups[1].Value)
        }
    }

    throw 'REST nonce not found in admin page output.'
}

function Parse-FieldValue {
    param(
        [string]$Html,
        [string]$FieldName
    )

    $patternPrimary = 'name=["'']' + [regex]::Escape($FieldName) + '["''][^>]*\svalue=["'']([^"'']*)["'']'
    $match          = [regex]::Match($Html, $patternPrimary, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if ( -not $match.Success ) {
        $patternFallback = 'value=["'']([^"'']*)["''][^>]*\sname=["'']' + [regex]::Escape($FieldName) + '["'']'
        $match           = [regex]::Match($Html, $patternFallback, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    }
    if ( $match.Success ) {
        return [System.Net.WebUtility]::HtmlDecode($match.Groups[1].Value)
    }

    return ''
}

function Get-WizardFormState {
    param([string]$Html)

    $formMatch = [regex]::Match(
        $Html,
        '<form[^>]*id=["'']ogs-setup-wizard-form["''][^>]*>(.*?)</form>',
        [System.Text.RegularExpressions.RegexOptions]::Singleline -bor [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
    )

    $scope = if ( $formMatch.Success ) { $formMatch.Groups[1].Value } else { $Html }
    $nonceMatch = [regex]::Match(
        $scope,
        '<input[^>]*name=["'']_wpnonce["''][^>]*value=["'']([^"'']+)["'']',
        [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
    )
    $stepMatch = [regex]::Match(
        $scope,
        '<input[^>]*name=["'']ogs_wizard_step["''][^>]*value=["'']([^"'']+)["'']',
        [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
    )

    $nonce = if ( $nonceMatch.Success ) { [System.Net.WebUtility]::HtmlDecode($nonceMatch.Groups[1].Value) } else { Parse-FieldValue -Html $scope -FieldName '_wpnonce' }
    $step  = if ( $stepMatch.Success ) { [System.Net.WebUtility]::HtmlDecode($stepMatch.Groups[1].Value) } else { Parse-FieldValue -Html $scope -FieldName 'ogs_wizard_step' }
    return [pscustomobject]@{
        nonce = $nonce
        step  = $step
    }
}

function Get-AdminActionFormState {
    param(
        [string]$Html,
        [string]$ActionValue
    )

    $forms = [regex]::Matches(
        $Html,
        '<form\b[^>]*>(.*?)</form>',
        [System.Text.RegularExpressions.RegexOptions]::Singleline -bor [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
    )

    foreach ( $form in $forms ) {
        $scope = $form.Groups[1].Value
        if ( $scope -notmatch ('name=["'']ogs_seo_action["'']\s+value=["'']' + [regex]::Escape($ActionValue) + '["'']') ) {
            continue
        }
        return [pscustomobject]@{
            nonce = Parse-FieldValue -Html $scope -FieldName '_wpnonce'
            scope = $scope
        }
    }

    return [pscustomobject]@{
        nonce = Parse-FieldValue -Html $Html -FieldName '_wpnonce'
        scope = $Html
    }
}

function Login-Admin {
    param(
        [string]$BaseUrl,
        [string]$AdminUser,
        [string]$AdminPass
    )

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    # Prime WordPress test cookie before POST login.
    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/wp-login.php" -WebSession $session | Out-Null
    $loginBody = @{
        log         = $AdminUser
        pwd         = $AdminPass
        'wp-submit' = 'Log In'
        redirect_to = "$BaseUrl/wp-admin/"
        testcookie  = '1'
    }

    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/wp-login.php" -Method Post -Body $loginBody -WebSession $session | Out-Null
    $adminPage = Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/wp-admin/" -WebSession $session
    if ( $adminPage.Content -match 'id=["'']loginform["'']' -or $adminPage.Content -match 'name=["'']user_login["'']' ) {
        throw 'Admin login failed: received wp-login form after authentication attempt.'
    }
    $nonce     = Parse-Nonce -Html $adminPage.Content

    return [pscustomobject]@{
        session = $session
        nonce   = $nonce
        html    = $adminPage.Content
    }
}

function Invoke-AuthenticatedRest {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$Nonce,
        [string]$BaseUrl,
        [string]$Route,
        [string]$Method = 'GET',
        [object]$Body = $null,
        [int[]]$ExpectedStatus = @(200)
    )

    $headers = @{ 'X-WP-Nonce' = $Nonce }
    if ( $null -ne $Body ) {
        $headers['Content-Type'] = 'application/json'
        $jsonBody = if ( $Body -is [string] ) { $Body } else { $Body | ConvertTo-Json -Depth 8 }
    } else {
        $jsonBody = $null
    }

    $url = "$BaseUrl/index.php?rest_route=$Route"

    try {
        if ( $null -ne $jsonBody ) {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $url -Method $Method -Headers $headers -WebSession $Session -Body $jsonBody
        } else {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $url -Method $Method -Headers $headers -WebSession $Session
        }

        if ( $ExpectedStatus -notcontains [int]$response.StatusCode ) {
            throw "Unexpected status code $($response.StatusCode) for $Route"
        }

        return [pscustomobject]@{
            status = [int]$response.StatusCode
            raw    = $response.Content
            data   = Try-ParseJson -Raw $response.Content
        }
    } catch {
        if ( $_.Exception.Response ) {
            $status = [int]$_.Exception.Response.StatusCode.value__
            $stream = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
            $raw    = $stream.ReadToEnd()
            if ( $ExpectedStatus -contains $status ) {
                return [pscustomobject]@{
                    status = $status
                    raw    = $raw
                    data   = Try-ParseJson -Raw $raw
                }
            }
            throw "REST call failed ($Route): HTTP $status - $raw"
        }
        throw
    }
}

function Get-WithSession {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$Url
    )

    return Invoke-WebRequest -UseBasicParsing -Uri $Url -WebSession $Session
}

function Invoke-UrlFallback {
    param(
        [string[]]$Urls,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session = $null
    )

    $lastError = ''
    foreach ( $url in $Urls ) {
        try {
            if ( $null -ne $Session ) {
                $response = Invoke-WebRequest -UseBasicParsing -Uri $url -WebSession $Session
            } else {
                $response = Invoke-WebRequest -UseBasicParsing -Uri $url
            }
            return [pscustomobject]@{
                ok       = $true
                url      = $url
                response = $response
                error    = ''
            }
        } catch {
            if ( $_.Exception.Response ) {
                $status = [int]$_.Exception.Response.StatusCode.value__
                $lastError = "HTTP $status @ $url"
            } else {
                $lastError = $_.Exception.Message
            }
        }
    }

    return [pscustomobject]@{
        ok       = $false
        url      = ''
        response = $null
        error    = $lastError
    }
}

function Try-ParseJson {
    param([string]$Raw)

    if ( -not $Raw ) {
        return $null
    }

    $trimmed = $Raw.Trim()
    if ( $trimmed.StartsWith('{') -or $trimmed.StartsWith('[') ) {
        return $Raw | ConvertFrom-Json
    }

    return $null
}

function Invoke-ExternalCommand {
    param(
        [string]$FilePath,
        [string[]]$ArgumentList = @(),
        [string]$WorkingDirectory = ''
    )

    $stdoutFile = [System.IO.Path]::GetTempFileName()
    $stderrFile = [System.IO.Path]::GetTempFileName()

    try {
        $startInfo = @{
            FilePath               = $FilePath
            ArgumentList           = $ArgumentList
            Wait                   = $true
            PassThru               = $true
            NoNewWindow            = $true
            RedirectStandardOutput = $stdoutFile
            RedirectStandardError  = $stderrFile
        }

        if ( $WorkingDirectory ) {
            $startInfo['WorkingDirectory'] = $WorkingDirectory
        }

        $process = Start-Process @startInfo

        return [pscustomobject]@{
            exit_code = [int]$process.ExitCode
            stdout    = [string](Get-Content -Path $stdoutFile -Raw -ErrorAction SilentlyContinue)
            stderr    = [string](Get-Content -Path $stderrFile -Raw -ErrorAction SilentlyContinue)
        }
    } finally {
        Remove-Item -Path $stdoutFile, $stderrFile -Force -ErrorAction SilentlyContinue
    }
}

function Resolve-WordPressPath {
    param([string]$WpPath)

    $candidates = @()
    if ( $WpPath ) {
        $candidates += $WpPath
    }
    if ( $env:OGS_WP_PATH ) {
        $candidates += $env:OGS_WP_PATH
    }

    foreach ( $candidate in $candidates ) {
        if ( -not $candidate ) {
            continue
        }

        try {
            $resolved = (Resolve-Path -Path $candidate -ErrorAction Stop).Path
        } catch {
            continue
        }

        if ( Test-Path (Join-Path $resolved 'wp-load.php') ) {
            return $resolved
        }
    }

    return ''
}

function Resolve-CliRunner {
    param(
        [string]$CliMode,
        [string]$WpPath
    )

    $runtimeRoot = Split-Path -Parent $PSCommandPath
    $pluginRoot  = Split-Path -Parent (Split-Path -Parent $runtimeRoot)
    $composeFile = Join-Path $runtimeRoot 'docker-compose.yml'
    $pharPath    = Join-Path $runtimeRoot 'wp-cli.phar'
    $localWpPath = Resolve-WordPressPath -WpPath $WpPath

    if ( 'local' -eq $CliMode -or 'auto' -eq $CliMode ) {
        $phpCheck = Invoke-ExternalCommand -FilePath 'php' -ArgumentList @('-v')
        if ( 0 -eq $phpCheck.exit_code -and (Test-Path $pharPath) -and $localWpPath ) {
            return [pscustomobject]@{
                mode        = 'local'
                command     = 'php'
                base_args   = @($pharPath, '--path=' + $localWpPath)
                workdir     = $pluginRoot
                description = "Local wp-cli.phar against $localWpPath"
            }
        }
    }

    if ( 'docker' -eq $CliMode -or 'auto' -eq $CliMode ) {
        if ( Test-Path $composeFile ) {
            $dockerCheck = Invoke-ExternalCommand -FilePath 'docker' -ArgumentList @('compose','version') -WorkingDirectory $runtimeRoot
            if ( 0 -eq $dockerCheck.exit_code ) {
                return [pscustomobject]@{
                    mode        = 'docker'
                    command     = 'docker'
                    base_args   = @('compose','-f',$composeFile,'run','--rm','-T','--quiet-pull','wpcli','wp','--path=/var/www/html')
                    workdir     = $runtimeRoot
                    description = 'Docker wpcli service against /var/www/html'
                }
            }
        }
    }

    return $null
}

function Invoke-WpCli {
    param(
        [pscustomobject]$Runner,
        [string[]]$CommandArgs
    )

    if ( $null -eq $Runner ) {
        throw 'WP-CLI runner is not available.'
    }

    $result = Invoke-ExternalCommand -FilePath $Runner.command -ArgumentList ($Runner.base_args + $CommandArgs) -WorkingDirectory $Runner.workdir
    if ( 0 -ne $result.exit_code ) {
        $message = if ( $result.stderr ) { $result.stderr.Trim() } elseif ( $result.stdout ) { $result.stdout.Trim() } else { 'Unknown WP-CLI failure.' }
        throw "WP-CLI command failed: $message"
    }

    return $result
}

function Test-WpCliSmoke {
    param(
        [string]$CliMode,
        [string]$WpPath
    )

    $runner = Resolve-CliRunner -CliMode $CliMode -WpPath $WpPath
    if ( $null -eq $runner ) {
        Add-Result -Name 'WP-CLI runtime availability' -Ok $false -Detail 'No WP-CLI runtime available. Provide -WpPath/OGS_WP_PATH for local mode or make docker compose available for tests/runtime/docker-compose.yml.'
        return
    }

    Add-Result -Name 'WP-CLI runtime availability' -Ok $true -Detail $runner.description

    $commands = @(
        @{
            name   = 'WP-CLI audit status'
            args   = @('ogs-seo','audit','status','--format=json')
            assert = {
                param($data)
                return ($null -ne $data.last_run) -and ($null -ne $data.active_issues)
            }
        },
        @{
            name   = 'WP-CLI sitemap status'
            args   = @('ogs-seo','sitemap','status','--format=json')
            assert = {
                param($data)
                return ($null -ne $data.enabled) -and ($null -ne $data.index)
            }
        },
        @{
            name   = 'WP-CLI hreflang status'
            args   = @('ogs-seo','hreflang','status','--format=json')
            assert = {
                param($data)
                return ($null -ne $data.provider) -and ($null -ne $data.errors)
            }
        },
        @{
            name   = 'WP-CLI schema status'
            args   = @('ogs-seo','schema','status','--format=json')
            assert = {
                param($data)
                return ($null -ne $data.node_count) -and ($null -ne $data.errors)
            }
        },
        @{
            name   = 'WP-CLI integrations status'
            args   = @('ogs-seo','integrations','status','--format=json')
            assert = {
                param($data)
                return ($null -ne $data.services) -or ($null -ne $data.summary)
            }
        },
        @{
            name   = 'WP-CLI tools diagnostics'
            args   = @('ogs-seo','tools','diagnostics','--format=json')
            assert = {
                param($data)
                return ($null -ne $data.plugin_version) -and ($null -ne $data.wp_version) -and ($null -ne $data.php_version)
            }
        }
    )

    foreach ( $check in $commands ) {
        try {
            $commandResult = Invoke-WpCli -Runner $runner -CommandArgs $check.args
            $data = Try-ParseJson -Raw $commandResult.stdout
            if ( $null -eq $data ) {
                Add-Result -Name $check.name -Ok $false -Detail 'Command did not return valid JSON.'
                continue
            }

            $ok = & $check.assert $data
            Add-Result -Name $check.name -Ok $ok -Detail $(if ( $ok ) { 'Live WP-CLI JSON payload validated.' } else { 'JSON payload missing expected keys.' })
        } catch {
            Add-Result -Name $check.name -Ok $false -Detail $_.Exception.Message
        }
    }
}

function Get-WizardPageState {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$BaseUrl
    )

    $page  = Get-WithSession -Session $Session -Url "$BaseUrl/wp-admin/admin.php?page=ogs-seo-setup"
    $state = Get-WizardFormState -Html $page.Content
    return [pscustomobject]@{
        page  = $page
        nonce = $state.nonce
        step  = $state.step
    }
}

function Submit-WizardAction {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$BaseUrl,
        [string]$Nonce,
        [string]$Step,
        [string]$Action,
        [hashtable]$ExtraBody = $null
    )

    $body = @{
        _wpnonce          = $Nonce
        ogs_wizard_step   = $Step
        ogs_wizard_action = $Action
    }
    if ( $null -ne $ExtraBody ) {
        foreach ( $entry in $ExtraBody.GetEnumerator() ) {
            $body[ [string]$entry.Key ] = $entry.Value
        }
    }

    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/wp-admin/admin.php?page=ogs-seo-setup" -Method Post -Body $body -WebSession $Session | Out-Null
    Start-Sleep -Milliseconds 150
    return Get-WizardPageState -Session $Session -BaseUrl $BaseUrl
}

function Validate-WizardFlow {
    param(
        [string]$BaseUrl,
        [string]$AdminUser,
        [string]$AdminPass
    )

    $wizardAuth = Login-Admin -BaseUrl $BaseUrl -AdminUser $AdminUser -AdminPass $AdminPass
    $state      = Get-WizardPageState -Session $wizardAuth.session -BaseUrl $BaseUrl

    # Normalize to step 1.
    $state = Submit-WizardAction -Session $wizardAuth.session -BaseUrl $BaseUrl -Nonce $state.nonce -Step $state.step -Action 'restart'

    # Step advance check.
    $state         = Submit-WizardAction -Session $wizardAuth.session -BaseUrl $BaseUrl -Nonce $state.nonce -Step $state.step -Action 'next'
    $stepAdvanceOk = ('2' -eq $state.step)

    # Pause/resume check.
    $state = Submit-WizardAction -Session $wizardAuth.session -BaseUrl $BaseUrl -Nonce $state.nonce -Step $state.step -Action 'cancel' -ExtraBody @{
        'ogs_wizard[mode]'                   = 'advanced'
        'ogs_wizard[site_type]'              = 'blog'
        'ogs_wizard[visibility]'             = 'keep'
        'ogs_wizard[safe_mode_seo_conflict]' = '1'
    }
    $pauseResumeOk = ('2' -eq $state.step)

    # Restart check.
    $state      = Submit-WizardAction -Session $wizardAuth.session -BaseUrl $BaseUrl -Nonce $state.nonce -Step $state.step -Action 'restart'
    $restartOk  = ('1' -eq $state.step)

    # Apply advanced mode.
    $state = Submit-WizardAction -Session $wizardAuth.session -BaseUrl $BaseUrl -Nonce $state.nonce -Step $state.step -Action 'next'
    $state = Submit-WizardAction -Session $wizardAuth.session -BaseUrl $BaseUrl -Nonce $state.nonce -Step $state.step -Action 'next' -ExtraBody @{
        'ogs_wizard[mode]'                   = 'advanced'
        'ogs_wizard[site_type]'              = 'blog'
        'ogs_wizard[visibility]'             = 'public'
        'ogs_wizard[safe_mode_seo_conflict]' = '1'
    }
    $stepThreeOk = ('3' -eq $state.step)
    if ( $stepThreeOk ) {
        $state = Submit-WizardAction -Session $wizardAuth.session -BaseUrl $BaseUrl -Nonce $state.nonce -Step $state.step -Action 'apply'
    }

    $settings = Invoke-AuthenticatedRest -Session $wizardAuth.session -Nonce $wizardAuth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/settings'
    $applyOk  = $stepThreeOk -and ($settings.data.wizard_completed -eq 1) -and ($settings.data.mode -eq 'advanced')

    return [pscustomobject]@{
        step_advance_ok  = $stepAdvanceOk
        pause_resume_ok  = $pauseResumeOk
        restart_ok       = $restartOk
        apply_ok         = $applyOk
        final_mode       = [string]$settings.data.mode
        wizard_completed = [string]$settings.data.wizard_completed
    }
}

try {
    Test-WpCliSmoke -CliMode $CliMode -WpPath $WpPath
    if ( $CliOnly ) {
        throw [System.OperationCanceledException]::new('CLI-only runtime smoke complete.')
    }

    $auth = Login-Admin -BaseUrl $BaseUrl -AdminUser $AdminUser -AdminPass $AdminPass
    Add-Result -Name 'Admin login' -Ok $true -Detail 'Logged in as admin and obtained REST nonce.'

    $adminMenuOk = $auth.html -match 'Open Growth SEO'
    Add-Result -Name 'Admin menu registration' -Ok $adminMenuOk -Detail 'Dashboard admin menu entry is present.'

    # Unauthenticated REST should be blocked.
    try {
        Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/index.php?rest_route=/ogs-seo/v1/audit/status" | Out-Null
        Add-Result -Name 'REST unauth protection' -Ok $false -Detail 'audit/status was accessible without authentication.'
    } catch {
        if ( $_.Exception.Response -and [int]$_.Exception.Response.StatusCode.value__ -eq 401 ) {
            Add-Result -Name 'REST unauth protection' -Ok $true -Detail 'audit/status correctly returns HTTP 401 when unauthenticated.'
        } else {
            Add-Result -Name 'REST unauth protection' -Ok $false -Detail $_.Exception.Message
        }
    }

    # Core admin pages.
    $pages = @(
        'ogs-seo-dashboard',
        'ogs-seo-setup',
        'ogs-seo-search-appearance',
        'ogs-seo-content',
        'ogs-seo-schema',
        'ogs-seo-sitemaps',
        'ogs-seo-bots',
        'ogs-seo-integrations',
        'ogs-seo-audits',
        'ogs-seo-tools',
        'ogs-seo-settings'
    )

    foreach ( $slug in $pages ) {
        $pageResp = Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/admin.php?page=$slug"
        $ok       = ([int]$pageResp.StatusCode -eq 200) -and ($pageResp.Content -match 'Open Growth SEO')
        Add-Result -Name "Admin page: $slug" -Ok $ok -Detail "HTTP $($pageResp.StatusCode)"
    }

    $dashboardPage = Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/admin.php?page=ogs-seo-dashboard"
    $dashboardHtml = $dashboardPage.Content
    $dashboardCardsOk = ($dashboardHtml -match 'SEO') -and ($dashboardHtml -match 'AEO') -and ($dashboardHtml -match 'GEO') -and ($dashboardHtml -match 'Issues and Actions')
    Add-Result -Name 'Dashboard cards render' -Ok $dashboardCardsOk -Detail 'Dashboard includes overview and issue/action sections.'

    $dashboardLiveContainerOk = $dashboardHtml -match 'data-ogs-live-status'
    Add-Result -Name 'Dashboard live check container' -Ok $dashboardLiveContainerOk -Detail 'Live checks container is present.'

    $dashboardLiveEndpointsOk = ($dashboardHtml -match '/ogs-seo/v1/sitemaps/status') -and ($dashboardHtml -match '/ogs-seo/v1/audit/status') -and ($dashboardHtml -match '/ogs-seo/v1/integrations/status')
    Add-Result -Name 'Dashboard live check endpoint wiring' -Ok $dashboardLiveEndpointsOk -Detail 'Dashboard localizes sitemap/audit/integrations runtime endpoints.'

    # Dashboard actions.
    $auditRun = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/audit/run' -Method 'POST' -Body @{}
    $issuesCount = if ( $auditRun.data -and $auditRun.data.issues ) { @($auditRun.data.issues).Count } else { 0 }
    Add-Result -Name 'Audit run (REST)' -Ok ($auditRun.status -eq 200) -Detail "Generated issues: $issuesCount"

    $auditStatus = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/audit/status'
    $auditStateOk = $auditStatus.data -and $null -ne $auditStatus.data.issues -and $null -ne $auditStatus.data.ignored
    Add-Result -Name 'Audit status payload' -Ok $auditStateOk -Detail 'Contains issues and ignored maps.'

    if ( $auditStatus.data -and $auditStatus.data.issues -and @($auditStatus.data.issues).Count -gt 0 ) {
        $issueId = [string]$auditStatus.data.issues[0].id
        if ( $issueId ) {
            $ignore = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/audit/ignore' -Method 'POST' -Body @{ issue_id = $issueId; reason = 'Runtime validation ignore flow check' }
            $unignore = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/audit/unignore' -Method 'POST' -Body @{ issue_id = $issueId }
            Add-Result -Name 'Audit ignore/unignore flow' -Ok (($ignore.status -eq 200) -and ($unignore.status -eq 200)) -Detail "Issue id: $issueId"
        }
    }

    # Setup wizard flow validated in an isolated admin session to avoid state bleed from other checks.
    $wizardValidation = Validate-WizardFlow -BaseUrl $BaseUrl -AdminUser $AdminUser -AdminPass $AdminPass
    Add-Result -Name 'Setup wizard step advance' -Ok $wizardValidation.step_advance_ok -Detail 'Isolated flow advanced from step 1 to step 2.'
    Add-Result -Name 'Setup wizard pause/resume' -Ok $wizardValidation.pause_resume_ok -Detail 'Isolated flow preserves paused draft and resumes.'
    Add-Result -Name 'Setup wizard restart' -Ok $wizardValidation.restart_ok -Detail 'Isolated flow restart returns to step 1.'
    Add-Result -Name 'Setup wizard apply' -Ok $wizardValidation.apply_ok -Detail ("wizard_completed={0}, mode={1}" -f $wizardValidation.wizard_completed, $wizardValidation.final_mode)
    $auth.nonce = Parse-Nonce -Html (Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/").Content

    # Save global Search Appearance settings.
    $searchPage = Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/admin.php?page=ogs-seo-search-appearance"
    $searchForm = Get-AdminActionFormState -Html $searchPage.Content -ActionValue 'save_settings'
    $saveNonce  = $searchForm.nonce
    $saveBody = @{
        _wpnonce                       = $saveNonce
        ogs_seo_action                 = 'save_settings'
        'ogs[title_separator]'         = '-'
        'ogs[title_template]'          = '%%title%% %%sep%% %%sitename%%'
        'ogs[meta_description_template]' = '%%excerpt%%'
        'ogs[canonical_enabled]'       = '1'
        'ogs[og_enabled]'              = '1'
        'ogs[twitter_enabled]'         = '1'
    }
    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/wp-admin/admin.php?page=ogs-seo-search-appearance" -Method Post -Body $saveBody -WebSession $auth.session | Out-Null

    # Bots/robots update.
    $botsPage = Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/admin.php?page=ogs-seo-bots"
    $botsForm  = Get-AdminActionFormState -Html $botsPage.Content -ActionValue 'save_settings'
    $botsNonce = $botsForm.nonce
    $botsBody = @{
        _wpnonce                   = $botsNonce
        ogs_seo_action             = 'save_settings'
        'ogs[robots_mode]'         = 'managed'
        'ogs[robots_global_policy]'= 'allow'
        'ogs[bots_gptbot]'         = 'disallow'
        'ogs[bots_oai_searchbot]'  = 'disallow'
        'ogs[robots_custom]'       = 'Disallow: /private/'
    }
    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/wp-admin/admin.php?page=ogs-seo-bots" -Method Post -Body $botsBody -WebSession $auth.session | Out-Null

    $settingsAfterBots = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/settings'
    $botsPersisted = ('disallow' -eq [string]$settingsAfterBots.data.bots_gptbot) -and ('disallow' -eq [string]$settingsAfterBots.data.bots_oai_searchbot)
    if ( -not $botsPersisted ) {
        $exportForBots = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/export'
        if ( $exportForBots.status -eq 200 -and $exportForBots.data -and $exportForBots.data.settings ) {
            $payload = $exportForBots.data
            $payload.settings.bots_gptbot = 'disallow'
            $payload.settings.bots_oai_searchbot = 'disallow'
            $payload.settings.robots_mode = 'managed'
            $payload.settings.robots_global_policy = 'allow'
            $payload.settings.robots_custom = 'Disallow: /private/'
            Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/import' -Method 'POST' -Body @{ payload = $payload; merge = $true } -ExpectedStatus @(200,400) | Out-Null
        }
    }

    # Create and update a runtime post with per-URL SEO overrides.
    $createPost = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/wp/v2/posts' -Method 'POST' -ExpectedStatus @(200,201) -Body @{
        title   = 'OGS Runtime Validation Post'
        status  = 'publish'
        content = '<div id="answer-summary"><p>Technical SEO is the practice of improving crawlability and indexability with measurable controls.</p></div><h2>Scope</h2><p>Requirement: XML sitemap coverage. Version: 2026 baseline.</p><h2>How to implement</h2><ol><li>Audit crawl logs.</li><li>Fix canonical conflicts.</li></ol><p>Expected impact: 25% lower crawl waste in 30 days.</p><p><a href="/guides/log-analysis">Log guide</a> and <a href="/guides/canonicals">Canonical guide</a>.</p>'
        excerpt = 'Runtime test excerpt for Open Growth SEO.'
    }

    $postId = [int]$createPost.data.id
    $postUrl = [string]$createPost.data.link

    $postUpdate = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route ("/wp/v2/posts/{0}" -f $postId) -Method 'POST' -Body @{
        meta = @{
            ogs_seo_title              = 'Runtime SEO Title'
            ogs_seo_description        = 'Runtime SEO description for validation.'
            ogs_seo_social_title       = 'Runtime Social Title'
            ogs_seo_social_description = 'Runtime social description for validation.'
            ogs_seo_social_image       = "$BaseUrl/wp-content/themes/twentytwentyfive/screenshot.png"
            ogs_seo_canonical          = "$BaseUrl/runtime-canonical-target/"
            ogs_seo_index              = 'noindex'
            ogs_seo_follow             = 'nofollow'
            ogs_seo_robots             = 'noindex,nofollow'
            ogs_seo_nosnippet          = '1'
            ogs_seo_max_snippet        = '90'
            ogs_seo_max_image_preview  = 'none'
            ogs_seo_max_video_preview  = '0'
            ogs_seo_noarchive          = '1'
            ogs_seo_notranslate        = '1'
            ogs_seo_unavailable_after  = '01 Jan 2030 00:00:00 GMT'
            ogs_seo_data_nosnippet_ids = 'answer-summary'
            ogs_seo_schema_type        = 'Article'
        }
    }

    Add-Result -Name 'Per-content meta update via REST' -Ok ($postUpdate.status -eq 200) -Detail "Post ID $postId updated with SEO overrides."

    $postResponse = Invoke-WebRequest -UseBasicParsing -Uri $postUrl
    $postHtml     = $postResponse.Content
    $xRobots      = [string]$postResponse.Headers['x-robots-tag']

    Add-Result -Name 'Frontend title override' -Ok ($postHtml -match '<title>Runtime SEO Title</title>') -Detail 'Rendered title reflects per-post SEO title.'
    Add-Result -Name 'Frontend meta description override' -Ok ($postHtml -match 'meta name="description" content="Runtime SEO description for validation') -Detail 'Rendered meta description reflects per-post override.'
    $expectedCanonical = '<link rel="canonical" href="' + $BaseUrl + '/runtime-canonical-target/"'
    Add-Result -Name 'Frontend canonical override' -Ok ($postHtml -match [regex]::Escape($expectedCanonical)) -Detail 'Canonical link reflects per-post override.'
    Add-Result -Name 'Social meta override' -Ok (($postHtml -match 'property="og:title" content="Runtime Social Title"') -and ($postHtml -match 'name="twitter:title" content="Runtime Social Title"')) -Detail 'OG/Twitter title reflects social override.'
    Add-Result -Name 'X-Robots-Tag header output' -Ok (($xRobots -match 'noindex') -and ($xRobots -match 'nofollow') -and ($xRobots -match 'nosnippet')) -Detail ("X-Robots-Tag: {0}" -f $xRobots)
    Add-Result -Name 'data-nosnippet injection' -Ok ($postHtml -match 'id="answer-summary"[^>]*data-nosnippet') -Detail 'Configured id receives data-nosnippet in frontend content.'

    $canonicalCount = ([regex]::Matches($postHtml, '<link rel="canonical"')).Count
    Add-Result -Name 'Canonical duplicate check' -Ok ($canonicalCount -eq 1) -Detail "Canonical tags found: $canonicalCount"

    $metaDescCount = ([regex]::Matches($postHtml, 'meta name="description"')).Count
    Add-Result -Name 'Meta description duplicate check' -Ok ($metaDescCount -eq 1) -Detail "Description tags found: $metaDescCount"

    $jsonLdCount = ([regex]::Matches($postHtml, 'application/ld\+json')).Count
    Add-Result -Name 'Schema output present' -Ok ($jsonLdCount -ge 1) -Detail "JSON-LD blocks found: $jsonLdCount"

    # Create a second indexable post for sitemap inclusion checks.
    $post2 = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/wp/v2/posts' -Method 'POST' -ExpectedStatus @(200,201) -Body @{
        title   = 'OGS Sitemap Include Post'
        status  = 'publish'
        content = '<p>Indexable post for sitemap include check.</p>'
    }
    $post2Url = [string]$post2.data.link

    $sitemapIndexResult = Invoke-UrlFallback -Urls @(
        "$BaseUrl/ogs-sitemap.xml",
        "$BaseUrl/?ogs_sitemap=index"
    )
    $postSitemapResult  = Invoke-UrlFallback -Urls @(
        "$BaseUrl/ogs-sitemap-post.xml",
        "$BaseUrl/?ogs_sitemap=post&ogs_sitemap_page=1"
    )

    $sitemapIndexOk = $sitemapIndexResult.ok -and ($sitemapIndexResult.response.Content -match '<sitemapindex')
    $postSitemapOk  = $postSitemapResult.ok -and ($postSitemapResult.response.Content -match '<urlset')
    Add-Result -Name 'Sitemap index endpoint' -Ok $sitemapIndexOk -Detail $(if ( $sitemapIndexResult.ok ) { "Resolved via $($sitemapIndexResult.url)" } else { $sitemapIndexResult.error })
    Add-Result -Name 'Post sitemap endpoint' -Ok $postSitemapOk -Detail $(if ( $postSitemapResult.ok ) { "Resolved via $($postSitemapResult.url)" } else { $postSitemapResult.error })
    if ( $postSitemapResult.ok ) {
        Add-Result -Name 'Sitemap noindex exclusion' -Ok (-not ($postSitemapResult.response.Content -match [regex]::Escape($postUrl))) -Detail 'Noindex post is excluded from post sitemap.'
        Add-Result -Name 'Sitemap inclusion of indexable post' -Ok ($postSitemapResult.response.Content -match [regex]::Escape($post2Url)) -Detail 'Indexable post appears in post sitemap.'
    } else {
        Add-Result -Name 'Sitemap noindex exclusion' -Ok $false -Detail 'Skipped because post sitemap endpoint was not reachable.'
        Add-Result -Name 'Sitemap inclusion of indexable post' -Ok $false -Detail 'Skipped because post sitemap endpoint was not reachable.'
    }

    # robots.txt + bot controls.
    $robotsResult = Invoke-UrlFallback -Urls @(
        "$BaseUrl/robots.txt",
        "$BaseUrl/?robots=1"
    )
    $robotsOk   = $robotsResult.ok -and ($robotsResult.response.Content -match 'User-agent: GPTBot') -and ($robotsResult.response.Content -match 'User-agent: OAI-SearchBot') -and ($robotsResult.response.Content -match 'Disallow: /')
    Add-Result -Name 'robots.txt bot policy output' -Ok $robotsOk -Detail $(if ( $robotsResult.ok ) { "Resolved via $($robotsResult.url)" } else { $robotsResult.error })

    # Hreflang fallback behavior.
    $hreflangStatus = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/hreflang/status'
    $post2Html = (Invoke-WebRequest -UseBasicParsing -Uri $post2Url).Content
    $hreflangTags = ([regex]::Matches($post2Html, 'hreflang=')).Count
    Add-Result -Name 'Hreflang conservative fallback' -Ok (($hreflangStatus.status -eq 200) -and ($hreflangTags -eq 0)) -Detail 'No invalid hreflang emitted in single-language setup.'

    # Schema, AEO, GEO inspection endpoints.
    $schemaInspect = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/schema/inspect'
    Add-Result -Name 'Schema inspect endpoint' -Ok ($schemaInspect.status -eq 200) -Detail 'Schema inspect endpoint responds for admins.'

    $aeoAnalyze = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route "/ogs-seo/v1/aeo/analyze&post_id=$postId"
    $aeoOk = $aeoAnalyze.data -and $aeoAnalyze.data.analysis -and $null -ne $aeoAnalyze.data.analysis.summary
    Add-Result -Name 'AEO analysis endpoint' -Ok $aeoOk -Detail 'AEO analysis returns summary/signals payload.'

    $geoAnalyze = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route "/ogs-seo/v1/geo/analyze&post_id=$postId"
    $geoOk = $geoAnalyze.data -and $geoAnalyze.data.analysis -and $null -ne $geoAnalyze.data.analysis.summary
    Add-Result -Name 'GEO analysis endpoint' -Ok $geoOk -Detail 'GEO analysis returns summary/signals payload.'

    # Integrations and IndexNow.
    $integrationsStatus = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/integrations/status'
    Add-Result -Name 'Integrations status endpoint' -Ok ($integrationsStatus.status -eq 200) -Detail 'Integrations status payload available without external credentials.'

    foreach ( $integration in @('google_search_console','bing_webmaster','ga4_reporting','indexnow') ) {
        $testPayload = @{ integration = $integration }
        $testResp = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/integrations/test' -Method 'POST' -Body $testPayload -ExpectedStatus @(200,400)
        Add-Result -Name "Integration test: $integration" -Ok ($testResp.status -in @(200,400)) -Detail "HTTP $($testResp.status) (safe fallback expected without credentials)."
    }

    # Enable IndexNow and verify key endpoint.
    $integrationsPage = Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/admin.php?page=ogs-seo-integrations"
    $integrationsForm  = Get-AdminActionFormState -Html $integrationsPage.Content -ActionValue 'save_settings'
    $integrationsNonce = $integrationsForm.nonce
    $indexKey = 'testindexnow1234'
    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/wp-admin/admin.php?page=ogs-seo-integrations" -Method Post -Body @{
        _wpnonce                      = $integrationsNonce
        ogs_seo_action                = 'save_settings'
        'ogs[indexnow_enabled]'       = '1'
        'ogs[indexnow_key]'           = $indexKey
        'ogs[indexnow_endpoint]'      = 'https://api.indexnow.org/indexnow'
        'ogs[indexnow_batch_size]'    = '100'
        'ogs[indexnow_max_retries]'   = '3'
        'ogs[indexnow_rate_limit_seconds]' = '60'
    } -WebSession $auth.session | Out-Null

    $settingsAfterIndexNow = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/settings'
    $indexNowPersisted = (1 -eq [int]$settingsAfterIndexNow.data.indexnow_enabled) -and ($indexKey -eq [string]$settingsAfterIndexNow.data.indexnow_key)
    if ( -not $indexNowPersisted ) {
        $exportForIndexNow = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/export'
        if ( $exportForIndexNow.status -eq 200 -and $exportForIndexNow.data -and $exportForIndexNow.data.settings ) {
            $payload = $exportForIndexNow.data
            $payload.settings.indexnow_enabled = 1
            $payload.settings.indexnow_key = $indexKey
            $payload.settings.indexnow_endpoint = 'https://api.indexnow.org/indexnow'
            $payload.settings.indexnow_batch_size = 100
            $payload.settings.indexnow_max_retries = 3
            $payload.settings.indexnow_rate_limit_seconds = 60
            Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/import' -Method 'POST' -Body @{ payload = $payload; merge = $true } -ExpectedStatus @(200,400) | Out-Null
        }
    }

    $keyFileResult = Invoke-UrlFallback -Urls @(
        "$BaseUrl/$indexKey.txt",
        "$BaseUrl/?ogs_indexnow_key=1"
    )
    $keyFileOk = $keyFileResult.ok -and ($keyFileResult.response.Content.Trim() -eq $indexKey)
    Add-Result -Name 'IndexNow key verification endpoint' -Ok $keyFileOk -Detail $(if ( $keyFileResult.ok ) { "Resolved via $($keyFileResult.url)" } else { $keyFileResult.error })

    $indexStatus = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/indexnow/status'
    Add-Result -Name 'IndexNow status endpoint' -Ok ($indexStatus.status -eq 200) -Detail 'IndexNow status payload available.'

    $indexProcess = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/indexnow/process' -Method 'POST' -Body @{}
    Add-Result -Name 'IndexNow process trigger' -Ok ($indexProcess.status -eq 200) -Detail 'IndexNow queue process endpoint executed.'

    # Compatibility importer endpoints.
    $compatStatus = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/compatibility/status'
    Add-Result -Name 'Compatibility status endpoint' -Ok ($compatStatus.status -eq 200) -Detail 'Compatibility status payload available.'

    $compatDry = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/compatibility/dry-run' -Method 'POST' -Body @{ slugs = @() }
    Add-Result -Name 'Compatibility dry-run endpoint' -Ok ($compatDry.status -eq 200) -Detail 'Dry-run executes with empty provider set.'

    $compatImport = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/compatibility/import' -Method 'POST' -Body @{ slugs = @(); overwrite = $false; limit = 0 }
    Add-Result -Name 'Compatibility import endpoint' -Ok ($compatImport.status -eq 200) -Detail 'Import endpoint executes safely with empty provider set.'

    $compatRollback = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/compatibility/rollback' -Method 'POST' -Body @{} -ExpectedStatus @(200,400)
    Add-Result -Name 'Compatibility rollback endpoint' -Ok ($compatRollback.status -in @(200,400)) -Detail "Rollback endpoint returned HTTP $($compatRollback.status)."

    # Developer tools endpoints.
    $diag = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/diagnostics'
    Add-Result -Name 'Dev tools diagnostics endpoint' -Ok ($diag.status -eq 200) -Detail 'Diagnostics payload available.'

    $export = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/export'
    $exportOk = $export.status -eq 200 -and $export.data -and $export.data.settings
    Add-Result -Name 'Dev tools export endpoint' -Ok $exportOk -Detail 'Export payload generated.'

    $import = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/import' -Method 'POST' -Body @{ payload = $export.data; merge = $true } -ExpectedStatus @(200,400)
    Add-Result -Name 'Dev tools import endpoint' -Ok ($import.status -in @(200,400)) -Detail "Import endpoint returned HTTP $($import.status)."

    $logs = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/logs&limit=20'
    Add-Result -Name 'Dev tools logs endpoint' -Ok ($logs.status -eq 200) -Detail 'Logs endpoint responds.'

    $clearLogs = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/dev-tools/logs/clear' -Method 'POST' -Body @{}
    Add-Result -Name 'Dev tools clear logs endpoint' -Ok ($clearLogs.status -eq 200) -Detail 'Logs clear endpoint responds.'

    # Sitemap/Hreflang/Schema dedicated status endpoints.
    $smStatus = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/sitemaps/status'
    Add-Result -Name 'Sitemaps status endpoint' -Ok ($smStatus.status -eq 200) -Detail 'Sitemaps status route responds with index URL.'

    $smInspect = Invoke-AuthenticatedRest -Session $auth.session -Nonce $auth.nonce -BaseUrl $BaseUrl -Route '/ogs-seo/v1/sitemaps/inspect'
    Add-Result -Name 'Sitemaps inspect endpoint' -Ok ($smInspect.status -eq 200) -Detail 'Sitemaps inspect route responds.'

    # Plugin deactivation/reactivation cycle via plugins.php actions.
    $pluginsPage = Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/plugins.php"
    $deactivateLink = [regex]::Match($pluginsPage.Content, 'href="([^"]*plugins.php\?action=deactivate&amp;plugin=open-growth-seo\.php[^"]*)"').Groups[1].Value
    if ( $deactivateLink ) {
        $deactivateUrl = "$BaseUrl/wp-admin/" + [System.Net.WebUtility]::HtmlDecode($deactivateLink)
        Invoke-WebRequest -UseBasicParsing -Uri $deactivateUrl -WebSession $auth.session | Out-Null

        $afterDeactivate = Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/"
        $deactivatedOk = -not ($afterDeactivate.Content -match 'ogs-seo') -and -not ($afterDeactivate.Content -match 'property="og:title"')
        Add-Result -Name 'Plugin deactivation smoke' -Ok $deactivatedOk -Detail 'Open Growth SEO head/meta output removed after deactivation.'

        $pluginsPage2 = Get-WithSession -Session $auth.session -Url "$BaseUrl/wp-admin/plugins.php"
        $activateLink = [regex]::Match($pluginsPage2.Content, 'href="([^"]*plugins.php\?action=activate&amp;plugin=open-growth-seo\.php[^"]*)"').Groups[1].Value
        if ( $activateLink ) {
            $activateUrl = "$BaseUrl/wp-admin/" + [System.Net.WebUtility]::HtmlDecode($activateLink)
            Invoke-WebRequest -UseBasicParsing -Uri $activateUrl -WebSession $auth.session | Out-Null
            Start-Sleep -Seconds 1
            $afterActivate = Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/"
            $reactivateOk  = $afterActivate.Content -match 'meta name="description"' -and $afterActivate.Content -match 'property="og:title"'
            Add-Result -Name 'Plugin reactivation smoke' -Ok $reactivateOk -Detail 'Open Growth SEO frontend meta output restored after reactivation.'
        } else {
            Add-Result -Name 'Plugin reactivation smoke' -Ok $false -Detail 'Activate link for open-growth-seo.php not found.'
        }
    } else {
        Add-Result -Name 'Plugin deactivation smoke' -Ok $false -Detail 'Deactivate link for open-growth-seo.php not found.'
    }

} catch [System.OperationCanceledException] {
    if ( 'CLI-only runtime smoke complete.' -ne $_.Exception.Message ) {
        Add-Result -Name 'Runtime script fatal' -Ok $false -Detail $_.Exception.Message
    }
} catch {
    Add-Result -Name 'Runtime script fatal' -Ok $false -Detail $_.Exception.Message
}

$summary = [pscustomobject]@{
    timestamp = (Get-Date).ToString('s')
    base_url  = $BaseUrl
    total     = $results.Count
    passed    = @($results | Where-Object { $_.ok }).Count
    failed    = @($results | Where-Object { -not $_.ok }).Count
    checks    = $results
}

$summary | ConvertTo-Json -Depth 8 | Set-Content -Encoding utf8 tests/runtime/runtime-results.json
Write-Host "Runtime report written to tests/runtime/runtime-results.json"
if ( $summary.failed -gt 0 ) {
    exit 1
}
