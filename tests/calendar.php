<?php
declare(strict_types=1);
/** No real Google requests. Fixtures are fabricated and live only in a temporary DB. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PLEROMA_TEST_MODE', true);
$integrationSource = file_get_contents(dirname(__DIR__) . '/app/integrations.php');
if (!str_contains($integrationSource, 'pleromaHttpTestTransport')) {
    fwrite(STDERR, "Calendar fixtures require the explicit CLI-only fake transport. No network request was made.\n");
    exit(1);
}
$testDir = sys_get_temp_dir() . '/pleroma-calendar-' . bin2hex(random_bytes(6));
mkdir($testDir, 0700, true);
$testConfig = [
    'base_url' => 'https://clinic.example.invalid/ordinacija', 'data_dir' => $testDir . '/data',
    'encryption_key' => bin2hex(random_bytes(32)), 'setup_token' => bin2hex(random_bytes(32)),
    'google_client_id' => 'fixture-client.invalid', 'google_client_secret' => 'fixture-secret',
    'google_calendar_id' => 'primary', 'allow_local_http' => true,
    'doctor_name' => 'Fixture Doctor', 'practice_name' => 'Fixture Practice',
];
file_put_contents($testDir . '/config.php', '<?php return ' . var_export($testConfig, true) . ';');
putenv('PLEROMA_CONFIG=' . $testDir . '/config.php');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
session_id('fixture-calendar-session');
require_once dirname(__DIR__) . '/app/integrations.php';

function calAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function calEqual(mixed $actual, mixed $expected, string $message): void {
    calAssert($actual === $expected, $message . ' — received ' . json_encode($actual));
}
function calThrows(callable $call, int $status): void {
    try { $call(); } catch (AppError $e) { calEqual($e->status, $status, 'Unexpected error status'); return; }
    throw new RuntimeException('Expected AppError ' . $status);
}
function calReset(): void {
    foreach (['records', 'revisions', 'settings', 'audit', 'attempts'] as $table) db()->exec('DELETE FROM ' . $table);
    $_SESSION = ['user' => true, 'issued' => time(), 'seen' => time()];
    $_GET = [];
    setSetting('google_tokens', ['access_token' => 'fixture-token', 'refresh_token' => 'fixture-refresh', 'expires_at' => time() + 3600]);
}
function calFixture(string $start = '2026-10-02T16:30:00Z'): array {
    $p = saveEntity('patients', ['name' => 'Fixture Osoba', 'phone' => '+381600000001',
        'email' => 'fixture-person@example.invalid', 'birthDate' => '1985-01-01',
        'city' => 'TEST_CITY_MARKER', 'diagnosis' => 'TEST_DIAG_MARKER',
        'notes' => 'TEST_NOTE_MARKER', 'allergies' => 'TEST_ALLERGY_MARKER']);
    $a = saveEntity('appointments', ['patientId' => $p['id'], 'start' => $start,
        'end' => gmdate('Y-m-d\TH:i:s\Z', strtotime($start) + 3000),
        'kind' => 'REBT', 'location' => 'TEST_LOCATION_MARKER', 'status' => 'scheduled']);
    return [$p, $a];
}
function calEdit(array $a, array $changes): array {
    $current = record('appointments', $a['id']);
    return saveEntity('appointments', array_merge($current, $changes));
}
final class CalendarFake {
    public array $events = [];
    public array $calls = [];
    public string $calendarId = 'fixture-calendar@example.invalid';
    public bool $dropInsert = false;
    public bool $dropDelete = false;
    public bool $dropPatch = false;
    public bool $insertConflict = false;
    public ?int $getFailure = null;
    public $onPatch = null;
    public array $tokenResponse = ['access_token' => 'fixture-new-token', 'refresh_token' => 'fixture-new-refresh', 'expires_in' => 3600,
        'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendars.readonly'];
    public function transport(string $url, string $method = 'GET', ?array $body = null, array $headers = [], bool $form = false): array {
        $this->calls[] = [$method, $url, $body];
        $path = parse_url($url, PHP_URL_PATH);
        if ($url === 'https://oauth2.googleapis.com/token') return ['status' => 200, 'data' => $this->tokenResponse];
        if (preg_match('~/calendar/v3/calendars/[^/]+$~D', $path)) {
            calEqual($method, 'GET', 'Calendar identity must be read-only');
            return ['status' => 200, 'data' => ['id' => $this->calendarId]];
        }
        calAssert(str_contains($path, '/events'), 'Unexpected fake endpoint');
        calAssert(in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true), 'Unexpected method');
        if ($method !== 'GET') calAssert(str_contains($url, 'sendUpdates=none'), 'No invitations or notification updates');
        $id = str_contains($path, '/events/') ? rawurldecode(substr($path, strrpos($path, '/') + 1)) : ($body['id'] ?? '');
        if ($method === 'GET') {
            if ($this->getFailure !== null) return ['status' => $this->getFailure, 'data' => []];
            return isset($this->events[$id]) ? ['status' => 200, 'data' => $this->events[$id]] : ['status' => 404, 'data' => []];
        }
        if ($method === 'POST') {
            calAssert((bool)preg_match('/^[0-9a-v]{5,1024}$/D', $id), 'Google ID format');
            if (isset($this->events[$id])) return ['status' => 409, 'data' => []];
            $this->events[$id] = $body;
            if ($this->dropInsert) { $this->dropInsert = false; fail('Fixture: lost insert response.', 502); }
            if ($this->insertConflict) { $this->insertConflict = false; return ['status' => 409, 'data' => []]; }
            return ['status' => 201, 'data' => $this->events[$id]];
        }
        if ($method === 'PATCH') {
            if (!isset($this->events[$id]) || ($this->events[$id]['status'] ?? '') === 'cancelled') return ['status' => 410, 'data' => []];
            $this->events[$id] = array_merge($this->events[$id], $body);
            if ($this->onPatch) { $fn = $this->onPatch; $this->onPatch = null; $fn(); }
            if ($this->dropPatch) { $this->dropPatch = false; fail('Fixture: lost patch response.', 502); }
            return ['status' => 200, 'data' => $this->events[$id]];
        }
        if (!isset($this->events[$id])) return ['status' => 410, 'data' => []];
        $this->events[$id] = ['id' => $id, 'status' => 'cancelled'];
        if ($this->dropDelete) { $this->dropDelete = false; fail('Fixture: lost delete response.', 502); }
        return ['status' => 204, 'data' => []];
    }
    public function activeCount(): int {
        return count(array_filter($this->events, fn($e) => ($e['status'] ?? 'confirmed') !== 'cancelled'));
    }
}
$testCount = 0; $failures = 0;
function calTest(string $name, callable $test): void {
    global $testCount, $failures;
    $testCount++;
    calReset();
    $fake = new CalendarFake();
    $GLOBALS['pleromaHttpTestTransport'] = [$fake, 'transport'];
    try { $test($fake); echo "PASS $name\n"; }
    catch (Throwable $e) { $failures++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}

calTest('OAuth start is authenticated, random and bound to this login session', function ($fake) {
    parse_str(parse_url(googleStart(), PHP_URL_QUERY), $q);
    calEqual($q['redirect_uri'], 'https://clinic.example.invalid/ordinacija/google-callback.php', 'Exact redirect');
    calAssert(strlen($q['state']) === 64, '256-bit state');
    calAssert(str_contains($q['scope'], 'calendar.calendars.readonly'), 'Read-only identity scope');
    calEqual($_SESSION['google_oauth']['state'], $q['state'], 'State in session');
    parse_str(parse_url(googleStart(), PHP_URL_QUERY), $q2);
    calAssert($q2['state'] !== $q['state'], 'New state per attempt');
    $_SESSION['user'] = false;
    calThrows(fn() => googleStart(), 401);
    calEqual(count($fake->calls), 0, 'Start does not call Google');
});
calTest('OAuth rejects mismatched, array, missing and old states without network', function ($fake) {
    googleStart();
    $_GET = ['state' => 'wrong', 'code' => 'fixture-code']; calThrows(fn() => googleCallback(), 403);
    $_GET['state'] = ['malformed']; calThrows(fn() => googleCallback(), 403);
    $_GET = []; calThrows(fn() => googleCallback(), 403);
    $_GET = ['state' => $_SESSION['google_oauth']['state'], 'code' => 'fixture-code'];
    $_SESSION['google_oauth']['created'] = time() - 601; calThrows(fn() => googleCallback(), 403);
    $_SESSION['google_oauth']['created'] = time() + 100; calThrows(fn() => googleCallback(), 403);
    calEqual(count($fake->calls), 0, 'Invalid states never reach token endpoint');
});
calTest('OAuth rejects another session and a new local login', function ($fake) {
    googleStart(); $_GET = ['state' => $_SESSION['google_oauth']['state'], 'code' => 'fixture-code'];
    $_SESSION['google_oauth']['session'] = hash('sha256', 'another-session'); calThrows(fn() => googleCallback(), 403);
    googleStart(); $_GET['state'] = $_SESSION['google_oauth']['state']; $_SESSION['issued']--;
    calThrows(fn() => googleCallback(), 403);
    calEqual(count($fake->calls), 0, 'No network from mismatched session');
});
calTest('Denied OAuth consent consumes state and preserves existing connection', function ($fake) {
    googleStart(); $_GET = ['state' => $_SESSION['google_oauth']['state'], 'error' => 'access_denied'];
    calThrows(fn() => googleCallback(), 400);
    calAssert(!isset($_SESSION['google_oauth']), 'One-time state consumed');
    calEqual(setting('google_tokens')['access_token'], 'fixture-token', 'Existing connection preserved');
    calEqual(count($fake->calls), 0, 'Denied consent never exchanged');
});
calTest('OAuth success stores encrypted tokens and refuses replay', function ($fake) {
    googleStart(); $_GET = ['state' => $_SESSION['google_oauth']['state'], 'code' => 'fixture-code'];
    googleCallback();
    calEqual(setting('google_tokens')['access_token'], 'fixture-new-token', 'Successful grant');
    $stored = db()->query("SELECT payload FROM settings WHERE key='google_tokens'")->fetchColumn();
    calAssert(!str_contains($stored, 'fixture-new-token'), 'Encrypted token record');
    calThrows(fn() => googleCallback(), 403);
    calEqual(count($fake->calls), 1, 'Replayed code not exchanged');
});
calTest('Wrong grant scope does not replace connection', function ($fake) {
    $fake->tokenResponse['scope'] = 'https://www.googleapis.com/auth/drive.readonly';
    googleStart(); $_GET = ['state' => $_SESSION['google_oauth']['state'], 'code' => 'fixture-code'];
    calThrows(fn() => googleCallback(), 403);
    calEqual(setting('google_tokens')['access_token'], 'fixture-token', 'Prior credentials preserved');
});
calTest('Partial Calendar consent is rejected before replacing connection', function ($fake) {
    $fake->tokenResponse['scope'] = 'https://www.googleapis.com/auth/calendar.events';
    googleStart(); $_GET = ['state' => $_SESSION['google_oauth']['state'], 'code' => 'fixture-code'];
    calThrows(fn() => googleCallback(), 403);
    calEqual(setting('google_tokens')['access_token'], 'fixture-token', 'Prior connection survives missing metadata scope');
    $fake->tokenResponse['scope'] = 'https://www.googleapis.com/auth/calendar';
    googleStart(); $_GET = ['state' => $_SESSION['google_oauth']['state'], 'code' => 'fixture-code'];
    googleCallback();
    calEqual(setting('google_tokens')['access_token'], 'fixture-new-token', 'Full Calendar grant supplies both capabilities');
});
calTest('Access refresh retains refresh token when Google omits it', function ($fake) {
    setSetting('google_tokens', ['access_token' => 'expired', 'refresh_token' => 'fixture-refresh', 'expires_at' => 0]);
    unset($fake->tokenResponse['refresh_token']);
    calEqual(googleAccess(), 'fixture-new-token', 'Fresh access token');
    calEqual(setting('google_tokens')['refresh_token'], 'fixture-refresh', 'Refresh token retained');
});
calTest('Calendar payload only exports pseudonym and schedule, with Belgrade zone', function ($fake) {
    [$p, $a] = calFixture(); $event = googleEvent($a, $p); $wire = encode($event);
    foreach (['Fixture Osoba', '+381600000001', 'fixture-person@example.invalid', '1985-01-01', 'TEST_CITY_MARKER',
        'TEST_DIAG_MARKER', 'TEST_NOTE_MARKER', 'TEST_ALLERGY_MARKER', 'TEST_LOCATION_MARKER', 'REBT'] as $secret) {
        calAssert(!str_contains($wire, $secret), 'Patient field leaked to Calendar');
    }
    calEqual($event['summary'], 'Termin · ' . $p['code'], 'Pseudonymous title');
    calEqual($event['visibility'], 'private', 'Private event');
    calEqual($event['attendees'], [], 'No invitees');
    calEqual($event['start']['timeZone'], 'Europe/Belgrade', 'IANA time zone');
    $p['code'] = 'Fixture Osoba F41.1'; calEqual(googleEvent($a, $p)['summary'], 'Termin', 'Untrusted patient code never copied');
});
calTest('Summer/winter offsets map to correct UTC instants and DST duration', function ($fake) {
    [$p, $a] = calFixture();
    $a['start'] = '2026-07-02T18:30:00+02:00'; $a['end'] = '2026-07-02T19:20:00+02:00';
    calEqual(googleEvent($a, $p)['start']['dateTime'], '2026-07-02T16:30:00Z', 'Summer UTC');
    $a['start'] = '2026-12-02T18:30:00+01:00'; $a['end'] = '2026-12-02T19:20:00+01:00';
    calEqual(googleEvent($a, $p)['start']['dateTime'], '2026-12-02T17:30:00Z', 'Winter UTC');
    $a['start'] = '2026-10-25T02:30:00+02:00'; $a['end'] = '2026-10-25T02:30:00+01:00';
    $e = googleEvent($a, $p);
    calEqual(strtotime($e['end']['dateTime']) - strtotime($e['start']['dateTime']), 3600, 'Repeated local hour means one elapsed hour');
});
calTest('New export pins actual calendar ID and unchanged repeat does nothing', function ($fake) {
    [$p, $a] = calFixture(); $r = googleSync();
    calEqual($r['synced'], 1, 'First export'); calEqual($r['remaining'], 0, 'Queue drained');
    $map = setting(googleMapKey($a['id'])); calEqual($map['calendarId'], $fake->calendarId, 'Concrete identity, never primary');
    calEqual($map['phase'], 'active', 'Active map'); calEqual($fake->activeCount(), 1, 'One remote event');
    calEqual(googleSync()['synced'], 0, 'Unchanged event not reexported');
});
calTest('Lost insert response plus local edit retries the same remote ID', function ($fake) {
    [$p, $a] = calFixture(); $fake->dropInsert = true;
    $r = googleSync(); calEqual(count($r['errors']), 1, 'Lost response reported');
    $id = setting(googleMapKey($a['id']))['eventId'];
    calEqual(setting(googleMapKey($a['id']))['phase'], 'pending', 'ID reserved before call');
    calEdit($a, ['start' => '2026-10-02T18:00:00Z', 'end' => '2026-10-02T18:50:00Z']);
    calEqual(googleSync()['synced'], 1, 'Retry succeeds');
    calEqual(record('appointments', $a['id'])['googleEventId'], $id, 'Same ID after version change');
    calEqual($fake->activeCount(), 1, 'No duplicate');
    calEqual($fake->events[$id]['start']['dateTime'], '2026-10-02T18:00:00Z', 'Latest schedule applied');
});
calTest('Lost update response can be retried without creating another event', function ($fake) {
    [$p, $a] = calFixture(); googleSync(); $id = record('appointments', $a['id'])['googleEventId'];
    calEdit($a, ['start' => '2026-10-02T19:00:00Z', 'end' => '2026-10-02T19:50:00Z']);
    $fake->dropPatch = true; calEqual(count(googleSync()['errors']), 1, 'Update loss reported');
    calEqual(googleSync()['synced'], 1, 'Update retry'); calEqual($fake->activeCount(), 1, 'No extra event');
    calEqual(record('appointments', $a['id'])['googleEventId'], $id, 'ID unchanged');
});
calTest('Lost delete response retries idempotently and completes cancellation', function ($fake) {
    [$p, $a] = calFixture(); googleSync(); calEdit($a, ['status' => 'cancelled']);
    $fake->dropDelete = true; calEqual(count(googleSync()['errors']), 1, 'Delete loss reported');
    calEqual(googleSync()['synced'], 1, 'Deleted tombstone counted as success');
    calEqual($fake->activeCount(), 0, 'Remote event deleted');
    calEqual(setting(googleMapKey($a['id']))['phase'], 'deleted', 'Tombstone stored');
});
calTest('Cancel then reschedule reserves a new ID and only one active event', function ($fake) {
    [$p, $a] = calFixture(); googleSync(); $oldId = record('appointments', $a['id'])['googleEventId'];
    calEdit($a, ['status' => 'cancelled']); googleSync();
    calEdit($a, ['status' => 'scheduled']); calEqual(googleSync()['synced'], 1, 'Reactivated appointment exported');
    $newId = record('appointments', $a['id'])['googleEventId'];
    calAssert($newId !== $oldId, 'Deleted event ID not reused'); calEqual($fake->activeCount(), 1, 'Exactly one active event');
});
calTest('No-show cancellation and never-exported cancellation are safe', function ($fake) {
    [$p, $a] = calFixture(); calEdit($a, ['status' => 'noShow']);
    calEqual(googleSync()['synced'], 1, 'Missing remote event treated as deleted');
    calEqual($fake->activeCount(), 0, 'No event created for no-show');
    calEdit($a, ['status' => 'scheduled']); googleSync(); calEqual($fake->activeCount(), 1, 'Reopened appointment exported once');
});
calTest('Remote tombstone is replaced once when a local appointment changes', function ($fake) {
    [$p, $a] = calFixture(); googleSync(); $oldId = record('appointments', $a['id'])['googleEventId'];
    $fake->events[$oldId] = ['id' => $oldId, 'status' => 'cancelled'];
    calEdit($a, ['start' => '2026-10-02T19:00:00Z', 'end' => '2026-10-02T19:50:00Z']);
    calEqual(googleSync()['synced'], 1, 'Deleted external event reconciled');
    calAssert(record('appointments', $a['id'])['googleEventId'] !== $oldId, 'Fresh reserved ID');
    calEqual($fake->activeCount(), 1, 'One active replacement');
});
calTest('INSERT conflict discovers and updates existing owned event', function ($fake) {
    [$p, $a] = calFixture(); $fake->insertConflict = true;
    calEqual(googleSync()['synced'], 1, '409 recovered'); calEqual($fake->activeCount(), 1, 'No duplicate after conflict');
});
calTest('Unowned remote event is neither modified nor deleted', function ($fake) {
    [$p, $a] = calFixture(); $map = googleMap($a, $fake->calendarId);
    $fake->events[$map['eventId']] = ['id' => $map['eventId'], 'summary' => 'Unrelated fixture event', 'status' => 'confirmed'];
    calEqual(count(googleSync()['errors']), 1, 'Ownership mismatch reported');
    calEdit($a, ['status' => 'cancelled']); calEqual(count(googleSync()['errors']), 1, 'Unowned deletion refused');
    calEqual($fake->events[$map['eventId']]['summary'], 'Unrelated fixture event', 'Remote event unchanged');
    calEqual(count(array_filter($fake->calls, fn($c) => in_array($c[0], ['POST', 'PATCH', 'DELETE'], true))), 0, 'Only reads');
});
calTest('Local edit while Google answers remains queued for next sync', function ($fake) {
    [$p, $a] = calFixture(); googleSync();
    calEdit($a, ['location' => 'TEST_LOCAL_CHANGE']);
    $fake->onPatch = fn() => calEdit($a, ['start' => '2026-10-02T20:00:00Z', 'end' => '2026-10-02T20:50:00Z']);
    $r = googleSync(); calEqual($r['remaining'], 1, 'Concurrent local edit remains pending');
    calEqual(record('appointments', $a['id'])['googleDirty'], true, 'Dirty version retained');
    calEqual(googleSync()['remaining'], 0, 'Next transfer applies latest version');
    $id = record('appointments', $a['id'])['googleEventId'];
    calEqual($fake->events[$id]['start']['dateTime'], '2026-10-02T20:00:00Z', 'Latest change applied');
});
calTest('Another Google account cannot silently consume existing mappings', function ($fake) {
    [$p, $a] = calFixture(); googleSync(); calEdit($a, ['location' => 'TEST_LOCAL_CHANGE']);
    $fake->calendarId = 'different-calendar@example.invalid'; $calls = count($fake->calls);
    $r = googleSync(); calEqual(count($r['errors']), 1, 'Concrete target mismatch is blocked');
    calEqual(count($fake->calls) - $calls, 1, 'Only metadata read, no event sent to another account');
    calEqual(record('appointments', $a['id'])['googleDirty'], true, 'Pending change preserved');
});
calTest('Failure batch is bounded by attempted items, not success count', function ($fake) {
    for ($i = 1; $i <= 26; $i++) calFixture(sprintf('2027-02-%02dT16:00:00Z', $i));
    $fake->getFailure = 429; $r = googleSync();
    calEqual($r['attempted'], 25, 'Attempt limit'); calEqual(count($r['errors']), 25, 'Failures counted');
    calEqual($r['remaining'], 26, 'All failed/unattempted items retained');
    calEqual($fake->activeCount(), 0, 'No external creation');
});

function calRemoveTree(string $path): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $item = $path . '/' . $name;
        if (is_dir($item)) calRemoveTree($item); else @unlink($item);
    }
    @rmdir($path);
}
// Finish WAL use before removing fixtures; this also permits the optional WASM
// host filesystem to close its SQLite descriptors without a missing-WAL error.
db()->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');
calRemoveTree($testDir);
echo "Calendar fixtures: " . ($testCount - $failures) . "/$testCount passed; no live Google requests.\n";
exit($failures ? 1 : 0);
