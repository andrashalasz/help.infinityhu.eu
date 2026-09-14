<?php
/**
 * MINIMALIS Yii2 bootstrap, Composer/Packagist nelkul - kizarolag arra valo,
 * hogy a mellekelt HelpController/modellek/nezetek VALODI Yii2 kerettel,
 * VALODI PostgreSQL adatbazissal lefussanak, es lehessen oket screenshotolni.
 *
 * Ez NEM helyettesiti a ti Infinity-projicetek sajat bootstrapjat - ott mar
 * van composer.json, vendor/, config/web.php stb. Ott a fejlesztoi_csomag
 * fajljait egyszeruen bemasoljak a megfelelo mappakba.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('YII_DEBUG', true);
define('YII_ENV', 'dev');

$fwPath = __DIR__ . '/_vendor/yii2fw/framework';
$hpPath = __DIR__ . '/_vendor/htmlpurifier/library';

// --- kezi PSR-4 autoloader: yii\... -> framework/, HTMLPurifier -> library/, app\... -> app/
spl_autoload_register(function ($class) use ($fwPath, $hpPath) {
    if (strpos($class, 'yii\\') === 0) {
        $rel = str_replace('\\', '/', substr($class, 4)) . '.php';
        $file = $fwPath . '/' . $rel;
        if (is_file($file)) { require $file; return; }
    }
    if (strpos($class, 'app\\') === 0) {
        $rel = str_replace('\\', '/', substr($class, 4)) . '.php';
        $file = __DIR__ . '/app/' . $rel;
        if (is_file($file)) { require $file; return; }
    }
    if ($class === 'HTMLPurifier' || strpos($class, 'HTMLPurifier_') === 0) {
        $rel = str_replace('_', '/', $class) . '.php';
        $file = $hpPath . '/' . $rel;
        if (is_file($file)) { require $file; return; }
    }
});

require $fwPath . '/Yii.php';

// HTMLPurifier autoload.php sajat registert is hasznal - ezt is bekotjuk
require $hpPath . '/HTMLPurifier.auto.php';

// --- egyszeru RBAC helyettesito: mindenki mindent tud, csak a teszthez.
// A yii\filters\AccessControl VALODI yii\web\User-t var (tipusellenorzessel),
// ezert nem duck-typing-gel helyettesitjuk, hanem a ket hivatalos interfeszt
// (IdentityInterface, rbac\ManagerInterface) implementaljuk minimalisan.
class TestIdentity implements yii\web\IdentityInterface
{
    public $id = 1;
    public static function findIdentity($id) { return new self(); }
    public static function findIdentityByAccessToken($token, $type = null) { return new self(); }
    public function getId() { return $this->id; }
    public function getAuthKey() { return 'x'; }
    public function validateAuthKey($authKey) { return true; }
}
class TestAuthManager extends \yii\base\Component implements yii\rbac\CheckAccessInterface
{
    public function checkAccess($userId, $permissionName, $params = []) { return true; }
    public function getPermissionsByUser($userId) { return ['help.view' => (object)['name' => 'help.view']]; }
}
class TestHelpHtmlWrap
{
    private $inner;
    public function __construct() { $this->inner = new \app\components\HelpHtml(); }
    public function __call($m, $a) { return call_user_func_array([$this->inner, $m], $a); }
}

$config = [
    'id' => 'help-teszt',
    'basePath' => __DIR__ . '/app',
    'controllerNamespace' => 'app\\controllers',
    'components' => [
        'user' => [
            'identityClass' => 'TestIdentity',
            'enableSession' => false,
            'enableAutoLogin' => false,
        ],
        'authManager' => [
            'class' => 'TestAuthManager',
        ],
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'pgsql:host=127.0.0.1;dbname=infinity_teszt',
            'username' => 'postgres',
            'password' => 'teszt123',
            'charset' => 'utf8',
        ],
        'request' => [
            'cookieValidationKey' => 'teszt-kulcs-1234567890',
            'baseUrl' => '',
        ],
        'assetManager' => [
            'basePath' => __DIR__ . '/app/web/assets',
            'baseUrl' => '/assets',
            // A valodi projektben ezeket Composer/Bower telepiti; a teszthez
            // ures csomagra cachereljuk oket, hogy ne akadjon el a publish().
            'bundles' => [
                'yii\web\YiiAsset' => ['sourcePath' => null, 'js' => [], 'css' => []],
                'yii\web\JqueryAsset' => ['sourcePath' => null, 'js' => [], 'css' => []],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'help' => 'help/index',
                'help/edit' => 'help/edit',
                'help/map' => 'help/map',
                'help/translate' => 'help/translate',
                'help/save-draft' => 'help/save-draft',
                'help/publish' => 'help/publish',
                'help/search' => 'help/search',
                'help/drawer' => 'help/drawer',
                // csak fejezetszammal KEZDODO slug (pl. 5-4-kintlevoseg-kezeles) megy
                // a view action-be - igy nem utkozik a fenti nevesitett route-okkal
                'help/<slug:\d[\w\-]*>' => 'help/view',
            ],
        ],
    ],
    'params' => [
        'help.mediaUrl' => '/media/',
    ],
];

$app = new yii\web\Application($config);
Yii::$app->set('helpHtml', new TestHelpHtmlWrap());
Yii::$app->user->setIdentity(new TestIdentity());
Yii::$app->language = $_GET['__lang'] ?? 'hu';

$app->run();
