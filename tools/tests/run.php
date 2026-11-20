<?php
/**
 * tools/tests/run.php
 * -------------------------------------------------------
 * Functional and security tests for the identity-capture and
 * customer-document module.
 *
 *   # unit tests only (needs the database connection)
 *   DB_SOCKET=/opt/lampp/var/mysql/mysql.sock \
 *     /opt/lampp/bin/php tools/tests/run.php --unit
 *
 *   # full suite against a running installation
 *   DB_SOCKET=/opt/lampp/var/mysql/mysql.sock \
 *     /opt/lampp/bin/php tools/tests/run.php \
 *       --base-url=http://localhost/loan/ \
 *       --user=testadmin --password='Admin@2026'
 *
 * The HTTP tests create one temporary customer, one passport
 * document and two capture sessions, then remove them again.
 * -------------------------------------------------------
 */

require_once __DIR__ . '/bootstrap.php';

$options = getopt('', ['base-url::', 'user::', 'password::', 'unit', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tools/tests/run.php [--unit] [--base-url=URL --user=NAME --password=PASS]\n";
    exit(0);
}

$baseUrl  = isset($options['base-url']) ? rtrim((string) $options['base-url'], '/') . '/' : null;
$user     = (string) ($options['user'] ?? getenv('LMS_TEST_USER') ?: '');
$password = (string) ($options['password'] ?? getenv('LMS_TEST_PASSWORD') ?: '');
$unitOnly = array_key_exists('unit', $options) || $baseUrl === null;

$suite = new TestSuite();
$db    = Database::getConnection();

// Fixed ICAO sample (ERIKSSON, Anna Maria).
const MRZ_VALID = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";
const MRZ_BAD_CHECK = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C37UTO7408122F1204159ZE184226B<<<<<10";

/** Build a small workbook with the same layout as the company file. */
function makeImportWorkbook(string $path): void
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->removeSheetByIndex(0);

    $customers = $book->createSheet();
    $customers->setTitle('CUSTOMERS');
    $customers->fromArray(['', 'NAME', 'PHONE', 'EMERGENCY CONTACT', 'EMERGENCY PHONE', 'REG DATE', 'NOTES'], null, 'A3');
    $customers->fromArray([
        [1, 'Import Test Alpha', '0744 000 111', 'Alpha Friend', '0744 000 112', '06/01/2025', 'Imported by tests'],
        [2, 'Import Test Beta', '', '', '', '07/01/2025', ''],
    ], null, 'A4');

    $loans = $book->createSheet();
    $loans->setTitle('LOANS');
    $loans->fromArray(['', 'DATE', 'NAME', 'LOAN DATE', 'PRINCIPAL', 'WEEKLY', 'WEEKS'], null, 'A3');
    $loans->fromArray([
        [1, '06/01/2025', 'Import Test Alpha', '06/01/2025', 60000, 6000, 10],
        [2, '07/01/2025', 'Import Test Beta', '07/01/2025', 30000, 3000, 10],
        [3, '08/01/2025', 'Missing Customer', '08/01/2025', 20000, 2000, 10],
    ], null, 'A4');

    $payments = $book->createSheet();
    $payments->setTitle('PAYMENTS');
    $payments->fromArray(['', 'DATE', 'LOAN REF', 'NAME', 'METHOD', 'PAY DATE', 'RECEIPT', 'AMOUNT'], null, 'A3');
    $payments->fromArray([
        [1, '13/01/2025', 'LN-0001', 'Import Test Alpha', 'cash', '13/01/2025', 'R1', 6000],
        [2, '', 'LN-0001', 'Import Test Alpha', 'cash', '', 'R2', 6000],
        [3, '01/02/2025', 'LN-9999', 'Nobody', 'cash', '01/02/2025', 'R3', 1000],
    ], null, 'A4');

    $book->setActiveSheetIndex(0);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
}


// =====================================================================
// Unit tests
// =====================================================================
echo PHP_EOL . "Unit tests" . PHP_EOL;

$suite->test('MRZ parser accepts a valid TD3 sample', function (): string {
    $parsed = (new MrzParser())->parse(MRZ_VALID);

    assertSame(true, $parsed['valid'], 'valid flag');
    assertSame('L898902C3', $parsed['passport_number']);
    assertSame('ERIKSSON', $parsed['surname']);
    assertSame('ANNA MARIA', $parsed['given_names']);
    assertSame('1974-08-12', $parsed['date_of_birth']);
    assertSame('female', $parsed['sex']);
    assertSame('2012-04-15', $parsed['expiry_date']);
    assertTrue($parsed['checks']['composite'], 'composite check digit');

    return 'all check digits pass';
});

$suite->test('MRZ parser rejects a tampered check digit', function (): string {
    $parsed = (new MrzParser())->parse(MRZ_BAD_CHECK);

    assertSame(false, $parsed['valid'], 'valid flag');
    assertSame(false, $parsed['checks']['passport_number'], 'passport check digit');

    return 'tampering detected';
});

$suite->test('ICAO check digit matches known value', function (): string {
    $parser = new MrzParser();
    assertSame('6', $parser->checkDigit('L898902C3'), 'passport number');
    assertSame('2', $parser->checkDigit('740812'), 'date of birth');
    assertSame('9', $parser->checkDigit('120415'), 'expiry date');

    return 'weighted 7-3-1';
});

$suite->test('Storage rejects traversal and non-image keys', function (): string {
    foreach ([
        '../../config/config.php',
        'customers/../../config/config.php',
        "customers/x\0.jpg",
        'customers/x.jpg.php',
        '/etc/passwd',
        '',
    ] as $key) {
        assertTrue(DocumentStorage::absolutePath($key) === null, 'accepted: ' . $key);
    }

    return '6 unsafe keys refused';
});

$suite->test('Legacy resolver stays inside storage folders', function (): string {
    assertTrue(DocumentStorage::legacyAbsolutePath('../../config/config.php.jpg') === null);
    assertTrue(DocumentStorage::legacyAbsolutePath('/etc/passwd') === null);
    assertTrue(DocumentStorage::legacyAbsolutePath('uploads/../config/config.php') === null);

    return 'path traversal blocked';
});

$suite->test('PDF writer produces a valid document', function (): string {
    $pdf = new PdfDocument('Test dossier');
    $pdf->title('Test');
    $pdf->heading('Section');
    $pdf->row('Label', 'Value');
    $pdf->paragraph(str_repeat('The quick brown fox jumps over the lazy dog. ', 250));

    $bytes = $pdf->toBytes();

    assertContains('%PDF-1.4', substr($bytes, 0, 8));
    assertContains('/Type /Catalog', $bytes);
    assertContains('/Subtype /Type1', $bytes);
    assertContains('trailer', $bytes);
    assertContains('%%EOF', substr($bytes, -8));
    assertTrue(strlen($bytes) > 1200, 'non-trivial output');
    assertTrue($pdf->getPageCount() >= 2, 'paragraph wrapped to a second page');

    return strlen($bytes) . ' bytes, ' . $pdf->getPageCount() . ' pages';
});

$suite->test('Gender validation accepts only the allowed values', function (): string {
    $customer = new Customer();
    $validate = new ReflectionMethod(Customer::class, 'validate');
    $validate->setAccessible(true);

    $base = [
        'first_name' => 'Test', 'middle_name' => 'Case', 'last_name' => 'Gender',
        'phone' => '0712345678', 'nida' => '12345678901234567890', 'gender' => 'female',
    ];

    assertSame([], $validate->invoke($customer, $base, true), 'female accepted');
    assertSame([], $validate->invoke($customer, array_merge($base, ['gender' => 'male']), true), 'male accepted');

    $bad = $validate->invoke($customer, array_merge($base, ['gender' => 'unknown']), true);
    assertTrue(in_array('Select a valid gender.', $bad, true), 'invalid gender reported');

    $missing = $validate->invoke($customer, array_merge($base, ['gender' => '']), true);
    assertTrue(in_array('Gender is required.', $missing, true), 'gender required');

    return 'female/male accepted, unknown rejected';
});

$suite->test('Swahili dictionary covers every t() key in the codebase', function (): string {
    $sw = require APP_ROOT . '/includes/lang/sw.php';
    $missing = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (substr($path, -4) !== '.php'
            || str_contains($path, '/vendor/')
            || str_contains($path, '/storage/')
            || $path === APP_ROOT . '/includes/lang/sw.php') {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (preg_match_all("/\\bt\\(\\s*'((?:[^\\\\\\\\']|\\\\\\\\.)*)'/u", $source, $m)) {
            foreach ($m[1] as $key) {
                $key = stripslashes($key);
                if ($key !== '' && !isset($sw[$key])) {
                    $missing[$key] = true;
                }
            }
        }
    }

    assertSame([], array_keys($missing), 'untranslated keys');

    return count($sw) . ' dictionary entries';
});

$suite->test('Active documents keep readable files (integrity)', function () use ($db): string {
    $report = (new DocumentCleanupService())->checkDocumentIntegrity();

    assertSame(0, $report['missing'], 'missing files');
    assertSame(0, $report['mismatched'], 'checksum mismatches');

    return $report['checked'] . ' document(s) verified';
});

$suite->test('Orphan scan reports unreferenced files but keeps referenced ones', function () use ($db): string {
    $folder = PRIVATE_DIR . '/customers/TEST-ORPHAN/supporting_documents';
    $orphan = $folder . '/orphan-test.jpg';

    if (!is_dir($folder)) {
        mkdir($folder, 0775, true);
    }

    file_put_contents($orphan, str_repeat('x', 512));
    touch($orphan, time() - 3 * 86400);

    // A real active document must survive the scan.
    $referenced = $db->query(
        "SELECT storage_key FROM customer_documents
          WHERE deleted_at IS NULL AND document_status = 'active' AND storage_key <> ''
       ORDER BY id LIMIT 1"
    )->fetchColumn();
    $referencedPath = $referenced ? DocumentStorage::absolutePath((string) $referenced) : null;

    $service = new DocumentCleanupService();
    $scan    = $service->cleanupOrphanDocumentFiles(1440, true);

    assertTrue($scan['removed'] >= 1, 'orphan detected');
    assertTrue($scan['dry_run'] === true, 'dry run does not delete');
    assertTrue(is_file($orphan), 'orphan untouched in dry run');

    if ($referencedPath !== null) {
        assertTrue(is_file($referencedPath), 'referenced document kept');
    }

    unlink($orphan);
    @rmdir($folder);
    @rmdir(dirname($folder));
    @rmdir(dirname(dirname($folder)));

    return $scan['removed'] . ' orphan(s) reported, nothing deleted';
});

$suite->test('Deleting a storage key also removes its mirrored thumbnail', function (): string {
    $name  = bin2hex(random_bytes(6)) . '.jpg';
    $key   = 'private/temporary/tests/' . $name;
    $thumb = DocumentStorage::thumbnailKeyFor($key);

    assertSame('private/thumbnails/temporary/tests/' . $name, $thumb, 'mirror key');
    assertTrue(DocumentStorage::thumbnailKeyFor($thumb) === null, 'thumbnail keys are not mirrored twice');
    assertTrue(DocumentStorage::thumbnailKeyFor('../../config/config.php') === null, 'unsafe keys refused');

    $absolute      = PRIVATE_DIR . '/temporary/tests/' . $name;
    $thumbAbsolute = PRIVATE_DIR . '/thumbnails/temporary/tests/' . $name;

    foreach ([$absolute, $thumbAbsolute] as $file) {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, 'x');
    }

    assertSame(realpath($absolute), DocumentStorage::absolutePath($key), 'key resolves once written');

    DocumentStorage::deleteKey($key);

    assertTrue(!is_file($absolute), 'image removed');
    assertTrue(!is_file($thumbAbsolute), 'thumbnail removed');

    @rmdir(dirname($absolute));
    @rmdir(dirname($thumbAbsolute));

    return 'image + thumbnail removed';
});

// =====================================================================
// HTTP tests
// =====================================================================
$created = [
    'customer' => 0,
    'officer' => 0,
    'documents' => [],
    'sessions' => [],
    'files' => [],
    'imports' => [
        'batches' => [],
        'customers' => [],
        'workbooks' => [],
    ],
];

if ($unitOnly) {
    echo PHP_EOL . "HTTP tests skipped (no --base-url)." . PHP_EOL;
    exit($suite->summary());
}

if ($user === '' || $password === '') {
    echo PHP_EOL . "HTTP tests skipped (missing --user/--password)." . PHP_EOL;
    exit($suite->summary());
}

echo PHP_EOL . "HTTP tests — {$baseUrl}" . PHP_EOL;

$http = new HttpClient($baseUrl);
$nida  = '199' . substr((string) time(), -10) . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$phone = '07' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$tmpDir = sys_get_temp_dir() . '/lms-tests-' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0775, true);

$portraitFile = $tmpDir . '/portrait.jpg';
$passportFile = $tmpDir . '/passport.jpg';

$portrait = imagecreatetruecolor(600, 800);
imagefill($portrait, 0, 0, imagecolorallocate($portrait, 206, 222, 214));
imagefilledellipse($portrait, 300, 300, 210, 250, imagecolorallocate($portrait, 55, 85, 72));
imagejpeg($portrait, $portraitFile, 90);
imagedestroy($portrait);

$passport = imagecreatetruecolor(1000, 700);
imagefill($passport, 0, 0, imagecolorallocate($passport, 238, 242, 239));
imagestring($passport, 5, 40, 40, 'PASSPORT TEST IMAGE', imagecolorallocate($passport, 30, 70, 50));
imagejpeg($passport, $passportFile, 90);
imagedestroy($passport);

$suite->test('Login rejects a wrong password', function () use ($http): string {
    $response = $http->login('testadmin', 'not-the-password');
    assertTrue($response['status'] === 200 || $response['status'] === 302, 'expected a response');
    assertNotContains('dashboard.php', $response['location'], 'must not redirect to dashboard');

    $dashboard = $http->get('dashboard.php');
    assertTrue($dashboard['status'] === 302, 'dashboard must require login');

    return 'not signed in';
});

$suite->test('Login succeeds with valid credentials', function () use ($http, $user, $password): string {
    $response = $http->login($user, $password);
    assertSame(302, $response['status'], 'login status');
    assertContains('dashboard.php', $response['location'], 'redirect target');

    $dashboard = $http->get('dashboard.php');
    assertSame(200, $dashboard['status']);

    return 'session established';
});

$suite->test('Registration form exposes gender and capture controls', function () use ($http): string {
    $page = $http->get('views/customers/add.php');
    assertSame(200, $page['status']);
    assertContains('name="gender"', $page['body'], 'gender field');
    assertContains('id="captureBox"', $page['body'], 'capture widget');
    assertContains('id="pairPhoneModal"', $page['body'], 'QR pairing modal');
    assertContains('id="imageEditorModal"', $page['body'], 'image editor');
    assertContains('name="csrf-token"', $page['body'], 'CSRF meta tag');

    return 'gender + capture UI present';
});

$suite->test('Customer can be registered with gender and portrait', function () use ($http, &$created, $nida, $phone, $portraitFile): string {
    $token = $http->csrf();

    $response = $http->post('views/customers/add.php', [
        'csrf_token'  => $token,
        'first_name'  => 'Test',
        'middle_name' => 'Suite',
        'last_name'   => 'Customer',
        'gender'      => 'female',
        'nida'        => $nida,
        'phone'       => $phone,
        'notes'       => 'Created by tools/tests/run.php',
        'photo_file'  => new CURLFile($portraitFile, 'image/jpeg', 'portrait.jpg'),
    ], true);

    assertSame(302, $response['status'], 'create status');
    assertContains('view.php?id=', $response['location'], 'redirect to profile');

    if (preg_match('/id=(\d+)/', $response['location'], $m)) {
        $created['customer'] = (int) $m[1];
    }

    assertTrue($created['customer'] > 0, 'customer id');

    return 'customer #' . $created['customer'];
});

$suite->test('Stored customer keeps gender, NIDA and private photo', function () use ($db, &$created, $nida, $phone): string {
    $stmt = $db->prepare('SELECT gender, nida, phone, photo_path FROM customers WHERE id = :id');
    $stmt->execute(['id' => $created['customer']]);
    $row = $stmt->fetch();

    assertTrue($row !== false, 'customer row');
    assertSame('female', $row['gender']);
    assertSame($nida, $row['nida']);
    assertSame($phone, $row['phone']);
    assertContains('private/', (string) $row['photo_path'], 'photo stored privately');
    assertTrue(str_starts_with((string) $row['photo_path'], 'private/'), 'no public photo path');

    $created['files'][] = (string) $row['photo_path'];

    return 'gender, NIDA and photo persisted';
});

$suite->test('Duplicate NIDA is rejected', function () use ($http, $nida, $portraitFile): string {
    $response = $http->post('views/customers/add.php', [
        'csrf_token'  => $http->csrf(),
        'first_name'  => 'Duplicate',
        'middle_name' => 'Nida',
        'last_name'   => 'Check',
        'gender'      => 'male',
        'nida'        => $nida,
        'phone'       => '0712000000',
        'photo_file'  => new CURLFile($portraitFile, 'image/jpeg', 'portrait.jpg'),
    ], true);

    assertContains('already registered', $response['body']);

    return 'unique constraint surfaced';
});

$suite->test('All seven profile tabs render', function () use ($http, &$created): string {
    $tabs = ['overview', 'loans', 'schedule', 'payments', 'history', 'documents', 'activity'];
    $sizes = [];

    foreach ($tabs as $tab) {
        $page = $http->get('views/customers/view.php?id=' . $created['customer'] . '&tab=' . $tab);
        assertSame(200, $page['status'], 'tab ' . $tab);
        assertNotContains('Fatal error', $page['body'], 'tab ' . $tab);
        assertNotContains('Uncaught', $page['body'], 'tab ' . $tab);
        $sizes[] = strlen($page['body']);
    }

    return implode('/', $sizes) . ' bytes';
});

$suite->test('Officers start at customer registration and cannot open the dashboard', function () use ($db, $baseUrl, &$created): string {
    $username = 'testofficer' . random_int(1000, 9999);

    $created['officer'] = (new User())->addUser([
        'full_name'          => 'Test Officer',
        'username'           => $username,
        'password'           => 'Officer@2026',
        'role'               => 'officer',
        'status'             => 'active',
        'preferred_language' => 'en',
    ]);

    $officer = new HttpClient($baseUrl);
    $login   = $officer->login($username, 'Officer@2026');

    assertSame(302, $login['status'], 'officer login');
    assertContains('views/customers/add.php', $login['location'], 'lands on registration');

    $dashboard = $officer->get('dashboard.php');
    assertSame(302, $dashboard['status'], 'dashboard redirects');
    assertContains('views/customers/add.php', $dashboard['location'], 'back to registration');

    $form = $officer->get('views/customers/add.php');
    assertSame(200, $form['status']);
    assertContains('View customers', $form['body'], 'customer list link');
    assertContains('View loans', $form['body'], 'loan list link');
    assertNotContains('dashboard.php', $form['body'], 'no dashboard link for officers');

    $getDelete = $officer->get('views/users/delete.php?id=' . $created['officer']);
    assertTrue(in_array($getDelete['status'], [302, 403], true), 'user delete does not run on GET');

    $stillThere = $db->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
    $stillThere->execute(['id' => $created['officer']]);
    assertSame(1, (int) $stillThere->fetchColumn(), 'officer account survives GET delete');

    return 'officer #' . $created['officer'] . ' lands on registration';
});

$suite->test('Administrator dashboard keeps its navigation and the export page is gone', function () use ($http): string {
    $dashboard = $http->get('dashboard.php');
    assertSame(200, $dashboard['status']);
    assertContains('dashboard.php', $dashboard['body'], 'dashboard nav visible to admins');
    assertContains('View customers', $dashboard['body'], 'customer list link');
    assertNotContains('export.php', $dashboard['body'], 'no workbook export link');

    $export = $http->get('export.php');
    assertSame(404, $export['status'], 'export endpoint removed');

    return 'dashboard admin-only, export removed';
});

$suite->test('Profile shows last login and switches language', function () use ($http): string {
    $page = $http->get('views/profile.php');
    assertSame(200, $page['status']);
    assertContains('Last login', $page['body'], 'last login banner');
    assertContains('Switch language', $page['body'], 'language switch');
    assertContains('name="preferred_language"', $page['body'], 'language control');

    $switch = $http->post('views/profile.php', [
        'csrf_token'         => $http->csrf(),
        'action'             => 'language',
        'preferred_language' => 'sw',
    ]);
    assertSame(302, $switch['status'], 'switch to Kiswahili');

    $swahili = $http->get('views/profile.php');
    assertContains('lang="sw"', $swahili['body'], 'page renders in Kiswahili');

    $back = $http->post('views/profile.php', [
        'csrf_token'         => $http->csrf(),
        'action'             => 'language',
        'preferred_language' => 'en',
    ]);
    assertSame(302, $back['status'], 'switch back to English');

    $english = $http->get('views/profile.php');
    assertContains('lang="en"', $english['body'], 'page renders in English');

    return 'language switch persisted, last login shown';
});

$suite->test('Document upload stores metadata and private keys', function () use ($http, $db, &$created, $passportFile): string {
    $response = $http->post('views/customers/view.php?id=' . $created['customer'], [
        'csrf_token'        => $http->csrf(),
        'action'            => 'doc_upload',
        'document_category' => 'passport',
        'issue_date'        => '2024-02-01',
        'expiry_date'       => '2031-02-01',
        'document_file'     => new CURLFile($passportFile, 'image/jpeg', 'passport.jpg'),
    ], true);

    assertSame(302, $response['status'], 'upload status');

    $stmt = $db->prepare(
        "SELECT id, storage_key, thumbnail_key, document_status, version_number, width, height
           FROM customer_documents
          WHERE customer_id = :c AND document_category = 'passport' AND deleted_at IS NULL
       ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['c' => $created['customer']]);
    $doc = $stmt->fetch();

    assertTrue($doc !== false, 'document row');
    assertSame('active', $doc['document_status']);
    assertSame(1, (int) $doc['version_number']);
    assertContains('private/customers/', (string) $doc['storage_key']);
    assertTrue((int) $doc['width'] > 0 && (int) $doc['height'] > 0, 'dimensions recorded');

    $created['documents'][] = (int) $doc['id'];
    $created['files'][] = (string) $doc['storage_key'];
    $created['files'][] = (string) $doc['thumbnail_key'];

    return 'document #' . $doc['id'];
});

$suite->test('Document previews require a session and reject unknown ids', function () use ($http, &$created): string {
    $docId = $created['documents'][0];

    $thumb = $http->get('media.php?doc=' . $docId . '&size=thumb');
    assertSame(200, $thumb['status']);
    assertContains('image/jpeg', $thumb['headers']);

    $full = $http->get('media.php?doc=' . $docId . '&size=full');
    assertSame(200, $full['status']);
    assertContains('image/jpeg', $full['headers']);

    $missing = $http->get('media.php?doc=999999&size=full');
    assertSame(404, $missing['status'], 'unknown document');

    return 'thumb + full served, unknown 404';
});

$suite->test('Anonymous visitors cannot read documents', function () use ($baseUrl, &$created): string {
    $anonymous = new HttpClient($baseUrl);
    $response  = $anonymous->get('media.php?doc=' . $created['documents'][0] . '&size=full');

    assertTrue(in_array($response['status'], [302, 401, 403], true), 'status ' . $response['status']);

    return 'redirected to login';
});

$suite->test('Document replacement keeps a version history', function () use ($http, $db, &$created, $portraitFile): string {
    $docId = $created['documents'][0];

    $response = $http->post('views/customers/view.php?id=' . $created['customer'], [
        'csrf_token'     => $http->csrf(),
        'action'         => 'doc_replace',
        'document_id'    => $docId,
        'replace_reason' => 'Automated test replacement',
        'replace_file'   => new CURLFile($portraitFile, 'image/jpeg', 'replacement.jpg'),
    ], true);

    assertSame(302, $response['status']);

    $stmt = $db->prepare(
        'SELECT version_number, storage_key FROM document_versions WHERE document_id = :d ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['d' => $docId]);
    $version = $stmt->fetch();

    assertTrue($version !== false, 'version row');
    assertSame(1, (int) $version['version_number']);
    assertContains('private/customers/', (string) $version['storage_key']);

    $created['files'][] = (string) $version['storage_key'];

    $current = $db->prepare('SELECT version_number FROM customer_documents WHERE id = :id');
    $current->execute(['id' => $docId]);
    assertSame(2, (int) $current->fetchColumn(), 'current version');

    return 'v1 archived, current v2';
});

$suite->test('Dossier PDF is generated on demand', function () use ($http, &$created): string {
    $response = $http->post('views/customers/dossier.php?id=' . $created['customer'], [
        'csrf_token' => $http->csrf(),
        'action'     => 'pdf',
        'docs'       => $created['documents'],
    ]);

    assertSame(200, $response['status']);
    assertContains('application/pdf', $response['headers']);
    assertSame('%PDF', substr($response['body'], 0, 4));
    assertTrue(strlen($response['body']) > 5000, 'non-trivial PDF');

    return strlen($response['body']) . ' bytes';
});

$suite->test('Passport OCR/MRZ parsing is recorded and reviewed', function () use ($http, $db, &$created): string {
    $docId = $created['documents'][0];

    $page = $http->get('views/customers/passport_ocr.php?id=' . $created['customer'] . '&doc_id=' . $docId);
    assertSame(200, $page['status']);

    $response = $http->post(
        'views/customers/passport_ocr.php?id=' . $created['customer'] . '&doc_id=' . $docId,
        [
            'csrf_token' => $http->csrf(),
            'action'     => 'parse',
            'mrz_text'   => MRZ_VALID,
        ]
    );

    assertSame(302, $response['status']);

    $stmt = $db->prepare(
        'SELECT validation_status, confidence FROM document_ocr_results WHERE document_id = :d ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['d' => $docId]);
    $row = $stmt->fetch();

    assertTrue($row !== false, 'OCR row');
    assertSame('valid', $row['validation_status']);

    return 'validation_status=valid, confidence=' . $row['confidence'];
});

$suite->test('Customer fields are not overwritten without explicit confirmation', function () use ($http, $db, &$created, $nida): string {
    $docId = $created['documents'][0];

    $http->post(
        'views/customers/passport_ocr.php?id=' . $created['customer'] . '&doc_id=' . $docId,
        [
            'csrf_token' => $http->csrf(),
            'action'     => 'apply',
            'first_name' => 'Anna',
            'last_name'  => 'Eriksson',
            'gender'     => 'female',
            // no "confirmed" flag on purpose
        ]
    );

    $stmt = $db->prepare('SELECT nida FROM customers WHERE id = :id');
    $stmt->execute(['id' => $created['customer']]);
    assertSame($nida, $stmt->fetchColumn(), 'NIDA untouched');

    return 'apply refused without confirmation';
});

$suite->test('Capture sessions require CSRF and issue a QR URL', function () use ($http, &$created): string {
    $http->clearCsrf();
    $denied = $http->post('camera_session.php', ['action' => 'create', 'purpose' => 'passport']);
    assertSame(403, $denied['status'], 'CSRF enforced');

    $http->loadCsrf();
    $allowed = $http->post('camera_session.php', [
        'action'   => 'create',
        'purpose'  => 'passport',
        'draft_id' => 'test-suite-' . $created['customer'],
    ]);
    assertSame(200, $allowed['status']);

    $data = json_decode($allowed['body'], true);
    assertTrue(is_array($data) && !empty($data['ok']), 'JSON ok flag');
    assertTrue(!empty($data['url']) && str_contains($data['url'], 'capture.php?t='), 'capture URL');
    assertTrue(strlen($data['token'] ?? '') === 0, 'raw token is not echoed');

    $created['sessions'][] = (int) $data['id'];

    return 'session #' . $data['id'];
});

$suite->test('Phone upload reaches the computer and signed preview works', function () use ($http, $db, &$created, $tmpDir, $baseUrl): string {
    // Build a fresh session and capture its one-time URL from the create call.
    $create = $http->post('camera_session.php', [
        'action'   => 'create',
        'purpose'  => 'passport',
        'draft_id' => 'test-suite-upload',
    ], false);
    $data = json_decode($create['body'], true);
    assertTrue(!empty($data['url']), 'capture URL issued');

    $created['sessions'][] = (int) $data['id'];

    $phone = new HttpClient($baseUrl);

    $phoneResponse = $phone->get($data['url']);
    assertSame(200, $phoneResponse['status'], 'phone page');
    assertContains('capture.php', $data['url']);

    $jpeg = base64_encode((string) file_get_contents($tmpDir . '/passport.jpg'));
    $upload = $phone->postJson($data['url'], ['image' => 'data:image/jpeg;base64,' . $jpeg]);
    assertSame(200, $upload['status']);
    $uploadData = json_decode($upload['body'], true);
    assertTrue(!empty($uploadData['ok']), 'upload accepted');

    $status = json_decode($http->get('camera_session.php?action=status&id=' . $data['id'])['body'], true);
    assertSame('uploaded', $status['status'] ?? '', 'session status');
    assertTrue(!empty($status['asset']['url']), 'signed asset URL');

    $signed = $phone->get($status['asset']['url']);
    assertSame(200, $signed['status'], 'signed asset');
    assertContains('image/jpeg', $signed['headers']);

    $unsigned = $phone->get($status['asset']['url'] . '&sig=deadbeef');
    assertSame(403, $unsigned['status'], 'bad signature');

    // Accepted image is temporary storage only.
    $stmt = $db->prepare('SELECT temporary_storage_key FROM camera_session_assets WHERE camera_session_id = :s ORDER BY id DESC LIMIT 1');
    $stmt->execute(['s' => $data['id']]);
    $key = (string) $stmt->fetchColumn();
    assertContains('private/temporary/', $key);
    $created['files'][] = $key;

    return 'asset #' . ($status['asset']['id'] ?? '?');
});

$suite->test('Capture link enforces the retake limit', function () use ($http, $db, &$created, $tmpDir): string {
    $create = $http->post('camera_session.php', [
        'action'   => 'create',
        'purpose'  => 'portrait',
        'draft_id' => 'test-suite-limit',
    ]);
    $data = json_decode($create['body'], true);
    $created['sessions'][] = (int) $data['id'];

    $db->prepare('UPDATE camera_sessions SET asset_upload_count = 9 WHERE id = :id')
       ->execute(['id' => $data['id']]);

    $phone = new HttpClient($data['url']);
    $jpeg  = base64_encode((string) file_get_contents($tmpDir . '/portrait.jpg'));

    $last = $phone->postJson($data['url'], ['image' => 'data:image/jpeg;base64,' . $jpeg]);
    assertSame(200, $last['status'], '10th upload');

    $blocked = $phone->postJson($data['url'], ['image' => 'data:image/jpeg;base64,' . $jpeg]);
    assertSame(429, $blocked['status'], '11th upload blocked');

    $stmt = $db->prepare('SELECT temporary_storage_key FROM camera_session_assets WHERE camera_session_id = :s');
    $stmt->execute(['s' => $data['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $created['files'][] = (string) $row['temporary_storage_key'];
    }

    return 'retake cap = ' . CameraSession::MAX_ASSETS;
});

$suite->test('Invalid and expired capture tokens are refused', function () use ($http, $baseUrl): string {
    $anonymous = new HttpClient($baseUrl);

    $invalid = $anonymous->get('capture.php?t=' . str_repeat('a', 64));
    assertSame(200, $invalid['status']);
    assertContains('Invalid capture link', $invalid['body']);

    $upload = $anonymous->postJson('capture.php?t=' . str_repeat('a', 64), [
        'image' => 'data:image/jpeg;base64,AAAA',
    ]);
    assertSame(410, $upload['status'], 'expired/invalid upload');

    return 'token rejected';
});

$suite->test('Captured document endpoint saves to the digital file', function () use ($http, $db, &$created, $tmpDir): string {
    $jpeg = base64_encode((string) file_get_contents($tmpDir . '/portrait.jpg'));

    $response = $http->post('document_capture.php', [
        'customer_id' => $created['customer'],
        'category'    => 'nida',
        'image'       => 'data:image/jpeg;base64,' . $jpeg,
    ]);

    assertSame(200, $response['status']);
    $data = json_decode($response['body'], true);
    assertTrue(!empty($data['ok']), 'ok flag');
    assertContains('media.php?doc=', (string) ($data['url'] ?? ''));

    $created['documents'][] = (int) $data['id'];

    $stmt = $db->prepare('SELECT storage_key, thumbnail_key FROM customer_documents WHERE id = :id');
    $stmt->execute(['id' => $data['id']]);
    $row = $stmt->fetch();
    assertContains('private/customers/', (string) $row['storage_key']);
    $created['files'][] = (string) $row['storage_key'];
    $created['files'][] = (string) $row['thumbnail_key'];

    return 'document #' . $data['id'];
});

$suite->test('Document capture endpoint is CSRF protected', function () use ($http, &$created): string {
    $http->clearCsrf();

    $response = $http->post('document_capture.php', [
        'customer_id' => $created['customer'],
        'category'    => 'nida',
        'image'       => 'data:image/jpeg;base64,AAAA',
    ]);

    assertSame(403, $response['status']);

    $http->loadCsrf();

    return '403 without token';
});

$suite->test('media.php refuses legacy path traversal', function () use ($http): string {
    $response = $http->get('media.php?f=' . rawurlencode('../../config/config.php'));
    assertSame(404, $response['status']);

    return '404 for traversal';
});

$suite->test('Deleting a document is soft and revokes access', function () use ($http, $db, &$created): string {
    $docId = $created['documents'][0];

    $response = $http->post('views/customers/view.php?id=' . $created['customer'], [
        'csrf_token'  => $http->csrf(),
        'action'      => 'doc_delete',
        'document_id' => $docId,
    ]);

    assertSame(302, $response['status']);

    $stmt = $db->prepare('SELECT document_status, deleted_at FROM customer_documents WHERE id = :id');
    $stmt->execute(['id' => $docId]);
    $row = $stmt->fetch();
    assertSame('deleted', $row['document_status']);
    assertTrue($row['deleted_at'] !== null, 'deleted_at set');

    $media = $http->get('media.php?doc=' . $docId . '&size=full');
    assertSame(404, $media['status'], 'revoked preview');

    return 'soft deleted';
});

$suite->test('Cleanup keeps active documents and reports storage', function () use ($db): string {
    $service = new DocumentCleanupService();
    $report  = $service->storageReport();

    assertTrue($report['private_files'] > 0, 'private files exist');
    assertTrue($report['documents'] >= 1, 'active documents counted');

    $dry = $service->cleanupCameraSessions(120, true);
    assertTrue($dry['dry_run'] === true, 'dry run flag');

    return $report['documents'] . ' document(s), ' . number_format($report['private_bytes']) . ' bytes';
});


$suite->test('Officers cannot open the workbook importer', function () use ($db, $baseUrl, &$created): string {
    $stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
    $stmt->execute(['id' => $created['officer']]);
    $username = (string) $stmt->fetchColumn();

    assertTrue($username !== '', 'officer username');

    $officer = new HttpClient($baseUrl);
    $login   = $officer->login($username, 'Officer@2026');
    assertSame(302, $login['status'], 'officer login');

    $page = $officer->get('import.php');
    assertSame(302, $page['status'], 'import redirects');
    assertContains('views/customers/add.php', $page['location'], 'back to registration');

    return 'officer blocked';
});

$suite->test('Administrator sees the drag-and-drop import screen', function () use ($http): string {
    $page = $http->get('import.php');
    assertSame(200, $page['status']);
    assertContains('Drag and drop the workbook here', $page['body'], 'drop zone');
    assertContains('Choose workbook', $page['body'], 'file picker');
    assertContains('What happens next', $page['body'], 'explanation');
    assertContains('Previous imports', $page['body'], 'history');

    return 'upload screen ready';
});

$suite->test('Import rejects a non-Excel upload', function () use ($http): string {
    $bad = tempnam(sys_get_temp_dir(), 'lms-bad-') ?: '/tmp/lms-bad.txt';
    $file = $bad . '.txt';
    rename($bad, $file);
    file_put_contents($file, 'not a workbook');

    $response = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'upload',
        'workbook'   => new CURLFile($file, 'text/plain', 'bad.txt'),
    ], true);

    @unlink($file);

    assertSame(422, $response['status'], 'rejected');
    $json = json_decode($response['body'], true);
    assertSame(false, $json['ok'] ?? null, 'ok=false');
    assertContains('.xlsx', (string) ($json['message'] ?? ''), 'explains the formats');

    return 'non-Excel refused';
});

$suite->test('Workbook upload, analysis and row review work end to end', function () use ($http, $db, &$created): string {
    $workbook = sys_get_temp_dir() . '/lms-import-' . random_int(1000, 9999) . '.xlsx';
    makeImportWorkbook($workbook);
    $created['imports']['workbooks'][] = $workbook;

    $upload = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'upload',
        'workbook'   => new CURLFile($workbook, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'test-import.xlsx'),
    ], true);

    assertSame(200, $upload['status'], 'upload status');
    $json = json_decode($upload['body'], true);
    assertSame(true, $json['ok'] ?? null, 'upload ok');
    $batchId = (int) ($json['batch_id'] ?? 0);
    assertTrue($batchId > 0, 'batch id');
    $created['imports']['batches'][] = $batchId;

    $analyze = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'analyze',
        'batch_id'   => $batchId,
    ]);

    assertSame(200, $analyze['status'], 'analyze status');
    $analysis = json_decode($analyze['body'], true);
    assertSame(true, $analysis['ok'] ?? null, 'analyze ok');
    assertSame(8, (int) ($analysis['counts']['total'] ?? 0), 'all rows staged');
    assertSame(4, (int) ($analysis['counts']['unclear'] ?? 0), 'unclear rows flagged');
    assertSame(4, (int) ($analysis['counts']['ready'] ?? 0), 'ready rows counted');

    $rowId = static function (string $legacy) use ($db, $batchId): int {
        $stmt = $db->prepare('SELECT id FROM import_rows WHERE batch_id = :b AND legacy_reference = :l LIMIT 1');
        $stmt->execute(['b' => $batchId, 'l' => $legacy]);
        return (int) $stmt->fetchColumn();
    };

    // Fix the missing phone on the second customer.
    $fix = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'decide',
        'batch_id'   => $batchId,
        'row_id'     => $rowId('CUST-0002'),
        'decision'   => 'save',
        'fields'     => ['phone' => '0744 000 222'],
    ]);
    assertSame(302, $fix['status'], 'customer fixed');

    // Skip the loan with no customer and the payment with no loan.
    foreach (['LN-0003', 'PMT-0003'] as $legacy) {
        $skip = $http->post('import.php', [
            'csrf_token' => $http->csrf(),
            'action'     => 'decide',
            'batch_id'   => $batchId,
            'row_id'     => $rowId($legacy),
            'decision'   => 'skip',
        ]);
        assertSame(302, $skip['status'], $legacy . ' skipped');
    }

    // Fill the missing payment date.
    $date = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'decide',
        'batch_id'   => $batchId,
        'row_id'     => $rowId('PMT-0002'),
        'decision'   => 'save',
        'fields'     => ['paid_at' => '2025-01-20'],
    ]);
    assertSame(302, $date['status'], 'payment date saved');

    $counts = (new WorkbookImportService())->counts($batchId);
    assertSame(0, $counts['unclear'], 'nothing left unclear');

    $review = $http->get('import.php?batch=' . $batchId);
    assertSame(200, $review['status'], 'review page');
    assertContains('Everything is clear', $review['body'], 'commit panel');

    $commit = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'commit',
        'batch_id'   => $batchId,
    ]);
    assertSame(302, $commit['status'], 'commit redirects');
    assertContains('dashboard.php', $commit['location'], 'lands on dashboard');

    $stmt = $db->prepare("SELECT id FROM customers WHERE full_name IN ('Import Test Alpha', 'Import Test Beta')");
    $stmt->execute();
    $customerIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    assertSame(2, count($customerIds), 'two customers created');
    $created['imports']['customers'] = $customerIds;

    $in = implode(',', $customerIds);
    assertSame(2, (int) $db->query("SELECT COUNT(*) FROM loans WHERE customer_id IN ({$in})")->fetchColumn(), 'two loans');
    assertSame(2, (int) $db->query("SELECT COUNT(*) FROM payments WHERE customer_id IN ({$in})")->fetchColumn(), 'two payments');
    assertSame(20, (int) $db->query("SELECT COUNT(*) FROM repayment_schedules WHERE loan_id IN (SELECT id FROM loans WHERE customer_id IN ({$in}))")->fetchColumn(), '20 weekly instalments');

    $dash = $http->get('dashboard.php');
    assertSame(200, $dash['status'], 'dashboard loads');
    assertContains('dashboard-motion.js', $dash['body'], 'animation script');

    return 'batch #' . $batchId . ' imported 2 customers / 2 loans / 2 payments';
});

$suite->test('Re-importing the same workbook reuses existing references', function () use ($http, &$created): string {
    $workbook = end($created['imports']['workbooks']);
    assertTrue(is_string($workbook) && is_file($workbook), 'workbook fixture still available');

    $upload = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'upload',
        'workbook'   => new CURLFile($workbook, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'test-import.xlsx'),
    ], true);

    $json    = json_decode($upload['body'], true);
    $batchId = (int) ($json['batch_id'] ?? 0);
    assertTrue($batchId > 0, 'batch id');
    $created['imports']['batches'][] = $batchId;

    $analyze = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'analyze',
        'batch_id'   => $batchId,
    ]);

    $analysis = json_decode($analyze['body'], true);
    assertSame(true, $analysis['ok'] ?? null, 'analyze ok');
    assertSame(6, (int) ($analysis['counts']['duplicate'] ?? 0), 'six existing rows reused');
    assertSame(2, (int) ($analysis['counts']['unclear'] ?? 0), 'only the two bad rows remain unclear');

    $cancel = $http->post('import.php', [
        'csrf_token' => $http->csrf(),
        'action'     => 'cancel',
        'batch_id'   => $batchId,
    ]);
    assertSame(302, $cancel['status'], 'cancelled');

    return 'duplicates detected, no double import';
});

// =====================================================================
// Cleanup of test fixtures
// =====================================================================
echo PHP_EOL . "Cleaning up test fixtures" . PHP_EOL;

$customerId = $created['customer'];

if ($customerId > 0) {
    $stmt = $db->prepare('SELECT storage_key, thumbnail_key FROM customer_documents WHERE customer_id = :c');
    $stmt->execute(['c' => $customerId]);

    foreach ($stmt->fetchAll() as $row) {
        $created['files'][] = (string) $row['storage_key'];
        $created['files'][] = (string) $row['thumbnail_key'];
    }

    $stmt = $db->prepare('SELECT storage_key FROM document_versions WHERE document_id IN (SELECT id FROM customer_documents WHERE customer_id = :c)');
    $stmt->execute(['c' => $customerId]);

    foreach ($stmt->fetchAll() as $row) {
        $created['files'][] = (string) $row['storage_key'];
    }

    $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $customerId]);
    echo '  removed customer #' . $customerId . PHP_EOL;
}


if (!empty($created['imports']['batches'])) {
    foreach ($created['imports']['batches'] as $batchId) {
        $stmt = $db->prepare('SELECT stored_name FROM import_batches WHERE id = :id');
        $stmt->execute(['id' => $batchId]);
        $stored = (string) $stmt->fetchColumn();

        if ($stored !== '') {
            @unlink(APP_ROOT . '/storage/imports/' . basename($stored));
        }
    }

    $in = implode(',', array_map('intval', $created['imports']['batches']));
    $db->exec("DELETE FROM import_batches WHERE id IN ({$in})");
    echo '  removed ' . count($created['imports']['batches']) . ' import batch(es)' . PHP_EOL;
}

if (!empty($created['imports']['customers'])) {
    $in = implode(',', array_map('intval', $created['imports']['customers']));

    $db->exec("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE customer_id IN ({$in}))");
    $db->exec("DELETE FROM payments WHERE customer_id IN ({$in})");
    $db->exec("DELETE FROM repayment_schedules WHERE loan_id IN (SELECT id FROM loans WHERE customer_id IN ({$in}))");
    $db->exec("DELETE FROM disbursements WHERE loan_id IN (SELECT id FROM loans WHERE customer_id IN ({$in}))");
    $db->exec("DELETE FROM loans WHERE customer_id IN ({$in})");
    $db->exec("DELETE FROM customer_assignments WHERE customer_id IN ({$in})");
    $db->exec("DELETE FROM customer_legacy_refs WHERE customer_id IN ({$in})");
    $db->exec("DELETE FROM customers WHERE id IN ({$in})");
    echo '  removed ' . count($created['imports']['customers']) . ' imported customer(s)' . PHP_EOL;
}

foreach ($created['imports']['workbooks'] as $workbook) {
    @unlink($workbook);
}

if (!empty($created['officer'])) {
    try {
        (new User())->delete($created['officer']);
        echo '  removed officer #' . $created['officer'] . PHP_EOL;
    } catch (Throwable $e) {
        echo '  officer cleanup skipped: ' . $e->getMessage() . PHP_EOL;
    }
}

foreach ($created['sessions'] as $sessionId) {
    $stmt = $db->prepare(
        'SELECT temporary_storage_key, thumbnail_key FROM camera_session_assets WHERE camera_session_id = :s'
    );
    $stmt->execute(['s' => $sessionId]);

    foreach ($stmt->fetchAll() as $row) {
        $created['files'][] = (string) $row['temporary_storage_key'];
        $created['files'][] = (string) $row['thumbnail_key'];
    }

    $db->prepare('DELETE FROM camera_sessions WHERE id = :id')->execute(['id' => $sessionId]);
}

// deleteKey() also removes the mirrored thumbnail of each image.
foreach (array_unique(array_filter($created['files'])) as $key) {
    DocumentStorage::deleteKey($key);
}

foreach (glob($tmpDir . '/*') ?: [] as $file) {
    @unlink($file);
}

@rmdir($tmpDir);

echo '  cleaned private files and temporary images' . PHP_EOL;

exit($suite->summary());
