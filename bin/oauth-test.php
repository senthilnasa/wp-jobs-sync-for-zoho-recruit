<?php
/**
 * Standalone Zoho Recruit OAuth tester.
 *
 * One file, no WordPress and no dependencies, for answering one question
 * without a plugin in the way: does this Client ID, Secret, data centre and
 * scope list actually produce a working refresh token, and does that token
 * carry the permissions you think it does?
 *
 * It walks the same flow the plugin walks, against the same endpoints, with the
 * same parameters, then calls two Recruit endpoints so you can see which scopes
 * were really granted rather than which ones were asked for.
 *
 * USAGE
 *
 *   1. Set ACCESS_KEY below to any hard-to-guess string.
 *   2. Put the file somewhere it can be reached over HTTPS.
 *   3. In the Zoho API console, add the address it prints as an Authorized
 *      Redirect URI. Zoho allows several, so this can sit beside the one the
 *      plugin uses.
 *   4. Open it with your key:  https://example.com/oauth-test.php?key=YOUR_KEY
 *   5. DELETE IT when you are done.
 *
 * Nothing is written to disk. Tokens are printed once and then forgotten.
 *
 * Not part of the plugin. bin/ is excluded from the release package, so this
 * cannot reach a site by accident.
 *
 * @package JobsSyncForZohoRecruit
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/**
 * Required. Anything reaching this file without ?key=<this> is refused.
 *
 * A page that starts an OAuth flow and prints refresh tokens has no business
 * being open to whoever finds it, and "nobody knows the URL" is not a control.
 */
const ACCESS_KEY = '';

/** Zoho API console Client ID. Leave empty to type it into the form instead. */
const CLIENT_ID = '';

/** Zoho API console Client Secret. Leave empty to type it into the form. */
const CLIENT_SECRET = '';

/** com | eu | in | au | jp | ca | sa — must match where the Zoho account lives. */
const DATA_CENTER = 'com';

/**
 * Scopes to request.
 *
 * The module name is SINGULAR. Zoho's own worked examples show `jobopenings`,
 * which the authorization server rejects with "Invalid OAuth Scope / Scope does
 * not exist" while naming neither the offending scope nor the reason.
 *
 * settings.fields.READ is what /settings/fields needs. Without it that endpoint
 * answers 401 however many times you reconnect, because the permission was
 * never in the request.
 */
const SCOPES = 'ZohoRecruit.modules.jobopening.READ,ZohoRecruit.settings.fields.READ';

// ---------------------------------------------------------------------------

if ( 'cli' === PHP_SAPI ) {
	fwrite( STDERR, "This is a web script: OAuth needs a browser to redirect through.\n" );
	exit( 1 );
}

session_start();

/**
 * Accounts and API hosts for each Zoho data centre.
 *
 * @return array<string,array{accounts:string,api:string}>
 */
function jszr_test_hosts() {
	return array(
		'com' => array(
			'accounts' => 'https://accounts.zoho.com',
			'api'      => 'https://recruit.zoho.com',
		),
		'eu'  => array(
			'accounts' => 'https://accounts.zoho.eu',
			'api'      => 'https://recruit.zoho.eu',
		),
		'in'  => array(
			'accounts' => 'https://accounts.zoho.in',
			'api'      => 'https://recruit.zoho.in',
		),
		'au'  => array(
			'accounts' => 'https://accounts.zoho.com.au',
			'api'      => 'https://recruit.zoho.com.au',
		),
		'jp'  => array(
			'accounts' => 'https://accounts.zoho.jp',
			'api'      => 'https://recruit.zoho.jp',
		),
		'ca'  => array(
			'accounts' => 'https://accounts.zohocloud.ca',
			'api'      => 'https://recruit.zohocloud.ca',
		),
		'sa'  => array(
			'accounts' => 'https://accounts.zoho.sa',
			'api'      => 'https://recruit.zoho.sa',
		),
	);
}

/**
 * This script's own address, which is what Zoho has to redirect back to.
 *
 * @return string
 */
function jszr_test_redirect_uri() {
	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

	$host   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? $_SERVER['SCRIPT_NAME'] : '';

	return ( $https ? 'https://' : 'http://' ) . $host . $script;
}

/**
 * Escape a value for HTML output.
 *
 * @param mixed $value Value to print.
 * @return string
 */
function jszr_test_e( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

/**
 * Make an HTTP request and return the status, body and any transport error.
 *
 * @param string               $method  GET or POST.
 * @param string               $url     Target URL.
 * @param array<string,string> $body    POST fields.
 * @param array<int,string>    $headers Extra headers.
 * @return array{status:int,body:string,error:string}
 */
function jszr_test_request( $method, $url, array $body = array(), array $headers = array() ) {
	if ( ! function_exists( 'curl_init' ) ) {
		return array(
			'status' => 0,
			'body'   => '',
			'error'  => 'The cURL extension is not installed.',
		);
	}

	$curl = curl_init();

	curl_setopt( $curl, CURLOPT_URL, $url );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $curl, CURLOPT_TIMEOUT, 30 );
	curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, false );

	if ( ! empty( $headers ) ) {
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );
	}

	if ( 'POST' === $method ) {
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, http_build_query( $body ) );
	}

	$response = curl_exec( $curl );
	$status   = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
	$error    = curl_error( $curl );

	curl_close( $curl );

	return array(
		'status' => $status,
		'body'   => false === $response ? '' : (string) $response,
		'error'  => $error,
	);
}

/**
 * Pretty-print a JSON body, or return it unchanged when it is not JSON.
 *
 * @param string $body Response body.
 * @return string
 */
function jszr_test_pretty( $body ) {
	$decoded = json_decode( $body, true );

	if ( null === $decoded ) {
		return '' === trim( $body ) ? '(empty body)' : $body;
	}

	return (string) json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

// ---------------------------------------------------------------------------
// Gate
// ---------------------------------------------------------------------------

$jszr_key_supplied = isset( $_GET['key'] ) ? (string) $_GET['key'] : '';

header( 'Content-Type: text/html; charset=utf-8' );
header( 'X-Robots-Tag: noindex, nofollow' );

?><!doctype html>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zoho Recruit OAuth tester</title>
<style>
	body { font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 52rem; margin: 2rem auto; padding: 0 1rem; color: #1d2327; }
	h1 { font-size: 1.4rem; }
	h2 { font-size: 1.05rem; margin-top: 2rem; }
	code, pre { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; }
	code { padding: 0.1rem 0.35rem; word-break: break-all; }
	pre { padding: 0.75rem; overflow-x: auto; max-height: 22rem; }
	table { border-collapse: collapse; width: 100%; }
	th, td { text-align: left; padding: 0.5rem; border-bottom: 1px solid #dcdcde; vertical-align: top; word-break: break-all; }
	th { width: 12rem; color: #646970; font-weight: 600; }
	.btn { display: inline-block; background: #2271b1; color: #fff; padding: 0.6rem 1.2rem; border: 0; border-radius: 4px; text-decoration: none; font: inherit; cursor: pointer; }
	.warn { border-left: 4px solid #d63638; background: #fcf0f1; padding: 0.75rem 1rem; }
	.ok { color: #00844a; font-weight: 600; }
	.bad { color: #d63638; font-weight: 600; }
	label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 600; }
	input, select { width: 100%; padding: 0.5rem; border: 1px solid #8c8f94; border-radius: 4px; font: inherit; }
</style>

<h1>Zoho Recruit OAuth tester</h1>

<?php
if ( '' === ACCESS_KEY ) {
	echo '<div class="warn"><p><strong>Set ACCESS_KEY first.</strong></p>'
		. '<p>Open this file and set <code>ACCESS_KEY</code> to any hard-to-guess string, then reload with '
		. '<code>?key=YOUR_KEY</code>. Until then this page does nothing: it can start an OAuth flow and print '
		. 'refresh tokens, so it must not be open to anyone who finds the address.</p></div>';
	exit;
}

if ( ! hash_equals( ACCESS_KEY, $jszr_key_supplied ) ) {
	http_response_code( 403 );
	echo '<div class="warn"><p><strong>Wrong or missing key.</strong> Add <code>?key=YOUR_KEY</code> to the address.</p></div>';
	exit;
}

$jszr_hosts    = jszr_test_hosts();
$jszr_centre   = isset( $_REQUEST['dc'] ) ? (string) $_REQUEST['dc'] : DATA_CENTER;
$jszr_centre   = isset( $jszr_hosts[ $jszr_centre ] ) ? $jszr_centre : 'com';
$jszr_accounts = $jszr_hosts[ $jszr_centre ]['accounts'];
$jszr_api      = $jszr_hosts[ $jszr_centre ]['api'];
$jszr_redirect = jszr_test_redirect_uri();

$jszr_client_id = isset( $_REQUEST['client_id'] ) && '' !== $_REQUEST['client_id']
	? trim( (string) $_REQUEST['client_id'] )
	: CLIENT_ID;

$jszr_client_secret = isset( $_REQUEST['client_secret'] ) && '' !== $_REQUEST['client_secret']
	? trim( (string) $_REQUEST['client_secret'] )
	: CLIENT_SECRET;

$jszr_scopes = isset( $_REQUEST['scopes'] ) && '' !== $_REQUEST['scopes']
	? trim( (string) $_REQUEST['scopes'] )
	: SCOPES;

echo '<p>Redirect URI, which has to be registered in the Zoho API console exactly as shown:<br><code>'
	. jszr_test_e( $jszr_redirect ) . '</code></p>';

// ---------------------------------------------------------------------------
// Step 3: the callback from Zoho
// ---------------------------------------------------------------------------

if ( isset( $_GET['code'] ) || isset( $_GET['error'] ) ) {

	if ( isset( $_GET['error'] ) ) {
		echo '<h2>Zoho refused the authorization</h2>';
		echo '<div class="warn"><p><code>' . jszr_test_e( $_GET['error'] ) . '</code></p>';

		if ( false !== stripos( (string) $_GET['error'], 'scope' ) ) {
			echo '<p>Zoho rejects the whole request when any single scope is unrecognised, and does not say '
				. 'which one. The module name is singular: <code>ZohoRecruit.modules.jobopening.READ</code>, '
				. 'not <code>jobopenings</code>.</p>';
		}

		echo '</div><p><a class="btn" href="?key=' . jszr_test_e( $jszr_key_supplied ) . '">Start again</a></p>';
		exit;
	}

	$jszr_state_ok = isset( $_GET['state'], $_SESSION['jszr_test_state'] )
		&& hash_equals( (string) $_SESSION['jszr_test_state'], (string) $_GET['state'] );

	unset( $_SESSION['jszr_test_state'] );

	if ( ! $jszr_state_ok ) {
		echo '<div class="warn"><p><strong>State mismatch.</strong> The reply did not match the request this '
			. 'browser started, so it has been discarded. Start again in the same browser session.</p></div>';
		exit;
	}

	// Zoho names the accounts host to exchange the code against. Honour it: an
	// account can live somewhere other than the centre that was guessed.
	if ( isset( $_GET['accounts-server'] ) ) {
		$jszr_given = filter_var( (string) $_GET['accounts-server'], FILTER_VALIDATE_URL );

		if ( $jszr_given && preg_match( '~^https://accounts\.zoho(cloud)?\.[a-z.]+$~', rtrim( $jszr_given, '/' ) ) ) {
			$jszr_accounts = rtrim( $jszr_given, '/' );
		}
	}

	$jszr_exchange = jszr_test_request(
		'POST',
		$jszr_accounts . '/oauth/v2/token',
		array(
			'grant_type'    => 'authorization_code',
			'client_id'     => isset( $_SESSION['jszr_test_client_id'] ) ? $_SESSION['jszr_test_client_id'] : $jszr_client_id,
			'client_secret' => isset( $_SESSION['jszr_test_client_secret'] ) ? $_SESSION['jszr_test_client_secret'] : $jszr_client_secret,
			'redirect_uri'  => $jszr_redirect,
			'code'          => (string) $_GET['code'],
		)
	);

	$jszr_tokens = json_decode( $jszr_exchange['body'], true );
	$jszr_tokens = is_array( $jszr_tokens ) ? $jszr_tokens : array();

	echo '<h2>Token exchange</h2>';
	echo '<table><tr><th>HTTP status</th><td>' . jszr_test_e( $jszr_exchange['status'] ) . '</td></tr>';
	echo '<tr><th>Accounts host</th><td>' . jszr_test_e( $jszr_accounts ) . '</td></tr></table>';

	if ( '' !== $jszr_exchange['error'] ) {
		echo '<div class="warn"><p>Transport error: ' . jszr_test_e( $jszr_exchange['error'] ) . '</p></div>';
	}

	if ( empty( $jszr_tokens['refresh_token'] ) ) {
		echo '<div class="warn"><p><strong>No refresh token came back.</strong></p>';

		if ( isset( $jszr_tokens['error'] ) && 'invalid_code' === $jszr_tokens['error'] ) {
			echo '<p>An authorization code is single-use and expires within a couple of minutes. Reloading this '
				. 'page replays a spent code, which is what <code>invalid_code</code> means. Start again.</p>';
		}

		echo '</div><pre>' . jszr_test_e( jszr_test_pretty( $jszr_exchange['body'] ) ) . '</pre>';
		echo '<p><a class="btn" href="?key=' . jszr_test_e( $jszr_key_supplied ) . '">Start again</a></p>';
		exit;
	}

	$jszr_granted = isset( $jszr_tokens['scope'] ) ? (string) $jszr_tokens['scope'] : '';

	echo '<h2>Your refresh token</h2>';
	echo '<div class="warn"><p>A refresh token does not expire. Treat it like a password: copy it, then '
		. '<strong>delete this file</strong>. It is not written to disk anywhere.</p></div>';

	echo '<table>';
	echo '<tr><th>refresh_token</th><td><code>' . jszr_test_e( $jszr_tokens['refresh_token'] ) . '</code></td></tr>';
	echo '<tr><th>access_token</th><td><code>'
		. jszr_test_e( isset( $jszr_tokens['access_token'] ) ? $jszr_tokens['access_token'] : '' ) . '</code></td></tr>';
	echo '<tr><th>expires_in</th><td>'
		. jszr_test_e( isset( $jszr_tokens['expires_in'] ) ? $jszr_tokens['expires_in'] : '' ) . ' seconds</td></tr>';
	echo '<tr><th>api_domain</th><td>'
		. jszr_test_e( isset( $jszr_tokens['api_domain'] ) ? $jszr_tokens['api_domain'] : '' ) . '</td></tr>';
	echo '<tr><th>granted scope</th><td>'
		. jszr_test_e( '' === $jszr_granted ? '(not reported)' : $jszr_granted ) . '</td></tr>';
	echo '</table>';

	// The point of the exercise: what was asked for against what came back.
	if ( '' !== $jszr_granted ) {
		$jszr_asked     = array_filter( array_map( 'trim', explode( ',', $jszr_scopes ) ) );
		$jszr_got_these = array_map( 'trim', explode( ',', $jszr_granted ) );

		echo '<h2>Scopes: asked for against granted</h2><table>';

		foreach ( $jszr_asked as $jszr_scope ) {
			$jszr_has = in_array( $jszr_scope, $jszr_got_these, true );

			echo '<tr><th>' . jszr_test_e( $jszr_scope ) . '</th><td class="' . ( $jszr_has ? 'ok' : 'bad' ) . '">'
				. ( $jszr_has ? 'granted' : 'NOT granted' ) . '</td></tr>';
		}

		echo '</table>';
	}

	// And what those scopes can actually reach.
	$jszr_access = isset( $jszr_tokens['access_token'] ) ? (string) $jszr_tokens['access_token'] : '';
	$jszr_base   = ! empty( $jszr_tokens['api_domain'] )
		? rtrim( (string) $jszr_tokens['api_domain'], '/' )
		: $jszr_api;

	$jszr_header = array( 'Authorization: Zoho-oauthtoken ' . $jszr_access );

	$jszr_probes = array(
		'Read job openings' => $jszr_base . '/recruit/v2/JobOpenings?per_page=1',
		'Read field list'   => $jszr_base . '/recruit/v2/settings/fields?module=JobOpenings',
	);

	echo '<h2>What the token can reach</h2><table>';

	$jszr_field_status = 0;

	foreach ( $jszr_probes as $jszr_label => $jszr_url ) {
		$jszr_probe = jszr_test_request( 'GET', $jszr_url, array(), $jszr_header );
		$jszr_good  = $jszr_probe['status'] >= 200 && $jszr_probe['status'] < 300;

		if ( 'Read field list' === $jszr_label ) {
			$jszr_field_status = $jszr_probe['status'];
		}

		echo '<tr><th>' . jszr_test_e( $jszr_label ) . '</th><td class="' . ( $jszr_good ? 'ok' : 'bad' ) . '">HTTP '
			. jszr_test_e( $jszr_probe['status'] );

		// 204 means the call worked and there is simply nothing to return.
		if ( 204 === $jszr_probe['status'] ) {
			echo ' (no content — the call worked, the module is empty)';
		}

		if ( ! $jszr_good && 204 !== $jszr_probe['status'] ) {
			echo '<pre>' . jszr_test_e( jszr_test_pretty( $jszr_probe['body'] ) ) . '</pre>';
		}

		echo '</td></tr>';
	}

	echo '</table>';

	if ( 401 === $jszr_field_status ) {
		echo '<div class="warn"><p>The field list answered 401 while job openings worked. That is a missing '
			. '<code>ZohoRecruit.settings.fields.READ</code> scope, not a broken connection, and reconnecting '
			. 'without adding the scope to the request will fail in exactly the same way.</p></div>';
	}

	echo '<p><a class="btn" href="?key=' . jszr_test_e( $jszr_key_supplied ) . '">Start again</a></p>';
	exit;
}

// ---------------------------------------------------------------------------
// Step 2: send the browser to Zoho
// ---------------------------------------------------------------------------

if ( isset( $_POST['connect'] ) ) {

	if ( '' === $jszr_client_id || '' === $jszr_client_secret ) {
		echo '<div class="warn"><p>Client ID and Client Secret are both required.</p></div>';
	} else {
		$jszr_state = bin2hex( random_bytes( 16 ) );

		// The secret travels in the session, never on the redirect, so it stays
		// out of Zoho's logs and out of the browser's history entry.
		$_SESSION['jszr_test_state']         = $jszr_state;
		$_SESSION['jszr_test_client_id']     = $jszr_client_id;
		$_SESSION['jszr_test_client_secret'] = $jszr_client_secret;

		$jszr_auth_url = $jszr_accounts . '/oauth/v2/auth?' . http_build_query(
			array(
				'response_type' => 'code',
				'client_id'     => $jszr_client_id,
				'scope'         => $jszr_scopes,
				'redirect_uri'  => $jszr_redirect,
				// offline is what makes Zoho return a refresh token at all, and
				// consent forces the prompt even when already authorized, so a
				// second run does not silently return an access token only.
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $jszr_state,
			)
		);

		header( 'Location: ' . $jszr_auth_url, true, 302 );
		exit;
	}
}

// ---------------------------------------------------------------------------
// Step 1: the form
// ---------------------------------------------------------------------------

?>
<form method="post" action="?key=<?php echo jszr_test_e( $jszr_key_supplied ); ?>">
	<label for="client_id">Client ID</label>
	<input type="text" id="client_id" name="client_id" value="<?php echo jszr_test_e( $jszr_client_id ); ?>"
		autocomplete="off" placeholder="1000.XXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">

	<label for="client_secret">Client Secret</label>
	<input type="password" id="client_secret" name="client_secret"
		value="<?php echo jszr_test_e( $jszr_client_secret ); ?>" autocomplete="off">

	<label for="dc">Data centre</label>
	<select id="dc" name="dc">
		<?php foreach ( array_keys( $jszr_hosts ) as $jszr_key ) : ?>
			<option value="<?php echo jszr_test_e( $jszr_key ); ?>"
				<?php echo $jszr_key === $jszr_centre ? 'selected' : ''; ?>>
				<?php echo jszr_test_e( $jszr_key . ' — ' . $jszr_hosts[ $jszr_key ]['accounts'] ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<label for="scopes">Scopes</label>
	<input type="text" id="scopes" name="scopes" value="<?php echo jszr_test_e( $jszr_scopes ); ?>">

	<p style="margin-top:1.25rem">
		<button class="btn" type="submit" name="connect" value="1">Connect to Zoho</button>
	</p>
</form>

<h2>If Zoho says "Invalid OAuth Scope"</h2>
<p>The module name is singular. Zoho's own worked examples show <code>jobopenings</code>, and the authorization
server rejects it. Use <code>ZohoRecruit.modules.jobopening.READ</code>. Zoho refuses the whole request when any
one scope is unrecognised and does not say which, so a single wrong entry looks like a broken Client ID.</p>

<h2>When you are finished</h2>
<p>Delete this file. It prints refresh tokens, and a refresh token does not expire.</p>
