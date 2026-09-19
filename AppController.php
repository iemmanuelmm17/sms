<?php

/**
 * Application level Controller.
 *
 * This file is application-wide controller file. You can put all
 * application-wide controller-related methods here.
 *
 * PHP versions 4 and 5
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright 2005-2011, Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2005-2011, Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         CakePHP(tm) v 0.2.9
 *
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

/**
 * Application Controller.
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 */
define('CALL_REQUEST_CALL_BACK', 'Call-Back');
define('CALL_REQUEST_CALL_PLAY', 'Call-Play');
define('CALL_REQUEST_CALL_RECORD_ACCOUNT', 'Call-Record-Account');
define('CALL_REQUEST_CALL_RECORD_GREETING', 'Call-Record-Greeting');
define('CALL_REQUEST_CALL_RECORD_NAME', 'Call-Record-Name');
define('CALL_REQUEST_CALL_RECORD_REPLY', 'Call-Record-Reply');
define('CALL_REQUEST_CALL_RECORD_REPLY_GROUP', 'Call-Record-Reply-Group');
define('CALL_REQUEST_CALL_RECORD_REPLY_DOMAIN', 'Call-Record-Reply-Domain');
define('CALL_REQUEST_CALL_RECORD_WORDFILE', 'Call-Record-WordFile');
define('CALL_REQUEST_CALL_SAY_TIME', 'Call-Say-Time');
define('CALL_REQUEST_CALL_TO_ANNOUNCE', 'Call-To-Announce');

// 'with intro'
define('CALL_REQUEST_CALL_TO_FORWARD', 'Call-To-Forward'); // individual
define('CALL_REQUEST_CALL_TO_FORWARD_GROUP', 'Call-To-Forward-Group');
define('CALL_REQUEST_CALL_TO_FORWARD_DOMAIN', 'Call-To-Forward-Domain');

define('CALL_REQUEST_CALL_TO_TALK', 'Call-To-Talk');
define('CALL_REQUEST_CALL_TO_LISTEN', 'Call-To-Listen');

// 'without intro'
define('CALL_REQUEST_VMAIL_FORWARD', 'VMail-Forward'); // individual
define('CALL_REQUEST_VMAIL_FORWARD_GROUP', 'VMail-Forward-Group');
define('CALL_REQUEST_VMAIL_FORWARD_DOMAIN', 'VMail-Forward-Domain');

define('DISPLAY_CALL_RECORD_GREETING', 'Call-Record-Greeting');
define('DISPLAY_CALL_RECORD_NAME', 'Call-Record-Name');
define('DISPLAY_CALL_RECORD_REPLY', 'Call-Record-Reply');
define('DISPLAY_CALL_SAY_TIME', 'Call-Say-Time');
define('DISPLAY_CALL_TO_TALK', 'Call-To-Talk');

// status code constants, mapped from configuration entries defined in nsconfig
define('HTTP_STATUS_CODE_CREATE_SUCCESS', Configure::read('NsHttpStatusCodeSuccessCreate'));
define('HTTP_STATUS_CODE_UPDATE_SUCCESS', Configure::read('NsHttpStatusCodeSuccessUpdate'));
define('HTTP_STATUS_CODE_DELETE_SUCCESS', Configure::read('NsHttpStatusCodeSuccessDelete'));

if (!defined ('LIDF_LEA_TABLE' ))
    define('LIDF_LEA_TABLE', 'lidf_lea_config');

require(WWW_ROOT.DS.'../Vendor/password.php');

require_once(WWW_ROOT.DS.'../Vendor/vendor/autoload.php');

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;
use Prometheus\RenderTextFormat;

App::uses('ConnectionManager', 'Model');




class AppController extends Controller
{
    //'Domain','Subscriber','Device', // 'Scopepriv'
    public $uses = array();
    public $helpers = array('Encoding');
    public $persistModel = true;

    /**
     * Dispatcher.
     *
     * If the specified action exists in the specified channel's controller, invoke it;
     * otherwise, return an HTTP response status code of 404 (Not Found).
     *
     * After successful execution of the invoked action, the controller's corresponding
     * view code in index.ctp will be executed to generate any requested data returned
     * with an HTTP response status code of 200 (OK).
     */




    public function nslog($type, $message)
    {
        $this->nslog = new nslog();
        $this->nslog->write($type,$message,$this);
    }

    public $endpointsMap = array(
        'account'            => 'accounts',
        'address'            => 'addresses',
        'addressendpoints'   => 'addresses',
        'agent'              => 'callqueues',
        'agentlog'           => 'callqueues',
        'ani'                => 'anis',
        'answerrule'         => 'subscribers',
        'apikey'             => 'apikeys',
        'attendant'          => 'attendants',
        'attendee'           => 'meetings',
        'audio'              => 'music',
        'auditlog'           => 'auditlogs',
        'balance'            => 'balances',
        'backup'             => 'backups',
        'restore'            => 'backups',
        'bill'               => 'bills',
        'billinfo'           => 'billinfos',
        'call'               => 'calls',
        'callidemgr'         => 'callidemgrs',
        'callqueue'          => 'callqueues',
        'callqueueemailreport'    => 'callqueues',
        'callqueuereport'    => 'callqueues',
        'callqueuestat'      => 'callqueues',
        'cdr'                => 'cdrs',
        'cdr1'               => 'cdrs',
        'cdr2'               => 'cdrs2',
        'cdrexport'          => 'cdrExport',
        'cdrschedule'        => 'cdrSchedule',
        'charge'             => 'charges',
        'chart'              => 'charts',
        'conference'         => 'conferences',
        'conferencecdr'      => 'conferences',
        'connection'         => 'connections',
        'contact'            => 'contacts',
        'contacts'           => 'contacts',
        'dashboard'          => 'dashboards',
        'device'             => 'devices',
        'devicemodel'        => 'devices',
        'devicedefault'      => 'devices',
        'deviceprofile'      => 'deviceprofiles',
        'defaultvalue'       => 'settings',
        'dialplan'           => 'dialrules',
        'dialpolicy'         => 'dialpolices',
        'dialrule'           => 'dialrules',
        'disposition'        => 'callqueues',
        'domain'             => 'domains',
        'email'              => 'mailers',
        'elementdomain'      => 'settings',
        'event'              => 'events',
        'firebase'           => 'settings',
        'holiday'            => 'timeframes',
        'image'              => 'settings',
        'mac'                => 'devices',
        'moh'                => 'music',
        'meeting'            => 'meetings',
        'ndpserver'          => 'devices',
        'ndpserverList'      => 'devices',
        'oauth'              => 'oauth2',
        'participant'        => 'conferences',
        'permission'         => 'dialpolices',
        'phoneconfiguration' => 'phoneconfigurations',
        'phonenumber'        => 'dialrules',
        'postrating'         => 'postratings',
        'prometheus'         => 'settings',
        'push'               => 'pushs',
        'queued'             => 'callqueues',
        'queuedcall'         => 'callqueues',
        'quotaUsage'         => 'quotas',
        'quota'              => 'quotas',
        'rateplan'           => 'rateplans',
        'recording'          => 'recordings',
        'rechargecard'       => 'rechargecards',
        'reseller'           => 'resellers',
        'serviceplan'        => 'serviceplans',
        'setting'            => 'settings',
        'sfu'                => 'sfu',
        'speechcommand'      => 'speechcommand',
        'site'               => 'sites',
        'subscriber'         => 'subscribers',
        'statistics'         => 'statistics',
        'timeframe'          => 'timeframes',
        'timerange'          => 'timeranges',
        'template'           => 'settings',
        'turn'               => 'turns',
        'trace'              => 'traces',
        'uiconfig'           => 'settings',
        'uiconfigdef'        => 'settings',
        'video'              => 'video',
        'vmailnag'           => 'subscribers',
        'voice'              => 'voice',
        'voicemail'          => 'music',
        'upload'             => 'upload',
        'route'              => 'routes',
        'routecon'           => 'routes',
        'pwa'                => 'pwa',
        'message'            => 'messages',
        'messagesession'     => 'messages',
        'smsnumber'          => 'messages',
        'configuration'      => 'settings',
        'config-definition'  => 'settings',
        'connection'         => 'connections',
        'sslcert'            => 'settings',
    );

    public function beforeFilter() {
        if ($this->request->is('options')) {
            $this->sendOriginHeaders();

            header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS, HEAD, DELETE');
            header('Access-Control-Allow-Headers: X-Requested-By,Content-Type,Authorization');
            header('Access-Control-Max-Age: 86400');

            echo 'ok';
            $this->nullResponse();

            exit;
        }
        if (isset($_COOKIE['NSDEBUG']) && $_COOKIE["NSDEBUG"] >= 0) {
            Configure::write('nsdebug', $_COOKIE["NSDEBUG"]);
        }

        if (Configure::read('NsExposePrometheus'))
        {
            try {
                if (isset($this->request->query['object'])) {
                    $form = $this->request->query;
                    if (isset($this->request->params['form'])) {
                        if (isset($this->request->params['form']['file'])) {
                            $form['file'] = $this->request->params['form']['file'];
                        }
                    }
                } elseif (isset($this->request->params['form']['file'])) {
                    $form = $this->request->data;
                    $form['file'] = $this->request->params['form']['file'];
                } elseif (isset($this->request->data['object'])) {
                    $form = $this->request->data;
                }

                if (isset($form['object']))
                {
                    $registry = \Prometheus\CollectorRegistry::getDefault();
                    $counter = $registry->getOrRegisterCounter('netsapiens_api', 'requests', 'requests to netsapiens api', ['object']);
                    if (isset($form['object']) && isset($this->endpointsMap[$form['object']]))
                    $counter->incBy(1, [$form['object']]);
                    else {
                    $counter->incBy(1, ['unknown']);
                    }
                }
            }
            catch(Exception $e)
            {
                $this->nslog('error', 'Prometheus error: '.$e->getMessage());
            }
        }
    }

    public function getCallbackHostname()
    {
        if (Configure::read('NsCallbackHostname') != null && Configure::read('NsCallbackHostname')!="") {
            return Configure::read('NsCallbackHostname');
        } else {
            return gethostname();
        }
    }

    /*
     * Helper function to validate incoming request parameters
     * Arg list is introspected, allowing requested params to be passed
     *   as comma separated args [EG: validateFormParams('arg1','arg2'); ]
     *
     * Missing parameters automatically trigger errorResponse
     *
     */
    protected function validateFormParams()
    {
        $paramList = func_get_args();
        $form = array_shift($paramList);

        if( !is_array($form) )
            throw new Exception('First parameter of validateFormParams() must be the form data.');

        foreach ($paramList as $param) {
            if( !isset($form[$param]) )
            {
                if ($this->isV2())
                {
                    $this->fieldmap = new fieldmap();
                    if (isset($this->fieldmap->fields[$param]))
                        $this->errorResponse(400, "Bad request. Missing parameter '".$this->fieldmap->fields[$param]."'.");
                    else
                        $this->errorResponse(400, "Bad request. Missing parameter '$param'.");

                }
                else
                {
                    $this->errorResponse(400, "Bad request. Missing parameter '$param'.");
                }
            }

        }
    }

    protected function chopDataSet($dataArray, $start=0, $limit=100)
    {
        return array_slice($dataArray,$start,$limit);
    }

    protected function validateFormHasUid($form) {
        if (isset($form['uid']) && strpos($form['uid'], '@') !== false && substr( $form['uid'], 0, 1 ) !== "@") {
            if (!isset($form['user']) || !isset($form['domain'])) {
                list($form['user'], $form['domain']) = explode('@', $form['uid']);
            }
        } elseif (isset($form['domain']) && isset($form['user']) && !empty($form['domain']) && !empty($form['user'])) {
            if (!isset($form['uid'])) {
                $form['uid'] = $form['user'].'@'.$form['domain'];
            }
        } else {
            // If not enough supplied parameters, return status code 400 (Bad Request)
            echo "Bad request. Missing parameter 'uid' or 'user' and 'domain'.\n";
            $this->errorResponse(400, "Bad request. Missing parameter 'uid' or 'user' and 'domain'.");
        }
    }

    private function __checkAction($strAction)
    {
        switch ($strAction) {
            case 'list':
                return method_exists($this, 'list_object');
                break;

            default:
                // default to the "create" which require the highest authorization
                return method_exists($this, $strAction);
                break;
        }
    }

    private function __checkAuth($form)
    {   
        //Notes on function. 
        //This function is called to check if the request should have authentication checked. Its a meant to be a bypass for certain
        //requests that are not meant to go without authentication. We need to make sure that the bypasses are not used for any other
        // request though so we need to be careful and only allow localhost, or match the controller to avoid any security issues.
        // A return of "false" is a bypass of auth, where "true" is a request that should be authenticated.

        switch ($form['object']) {
            case 'apikey':
                if ( ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1" )) {
                    return false;
                }
                break;
            case 'uiconfig':
            case 'uiconfigdef':
            case 'image':
                if (isset($form['include_defaults'])) { //this is a request to read all configs
                    return true;
                }
                if ($form['action'] === 'read' || $form['action'] === 'count' || $form['action'] === 'list') {
                    if ($this->name == "Settings" || $this->name == "Uiconfig" || $this->name == "Uiconfigdef" || $this->name == "Image") {
                        return false;
                    }

                }
                break;

            case 'deviceprofile':
                if ($form['action'] === 'image') {
                    if ($this->name == "Deviceprofiles" || $this->name == "Deviceprofile" ) {
                        return false;
                    }
                    
                }
                break;

            case 'device':

                if ($form['action'] == 'cron' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1" )) {

                    return false;
                }
                break;

            case 'audio':
                if ($this->name == "Music" || $this->name == "Music" ) {
                    if ($form['action'] === 'play') {
                        return false;
                    }
                    if ($form['action'] == 'link_update' || $form['action'] == 'link_delete') {
                        return false;
                    }
                    if (strpos($form['action'], 'remote_') !== false && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                }
            
                break;

            case 'cdrexport':
                if ($this->name == "CdrExport" || $this->name == "Cdrexport" ) {
                    if (($form['action'] == 'download') || ($form['action'] === 'create' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1"))) {
                        return false;
                    }
                }
                break;

            case 'cdrschedule':
            case 'callqueueemailreport':
                if ($this->name == "CdrSchedule" || $this->name == "Callqueues" ) {
                    if ($form['action'] == 'update' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    if ($form['action'] == 'delete' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                }
                break;
            case 'sms':
                if ($this->name == "Messages" ) {
                    if ($form['action'] === 'create') {
                        return false;
                    }
                }
                break;

            case 'pwa':
                if ($this->name == "Pwa" ) {
                    return false;
                }
                break;
            case 'message':
                if ($this->name == "Messages" ) {
                    if ($form['action'] === 'read_media') {
                        return false;
                    }
                    if ($form['action'] === 'clean_media' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    if ($form['action'] === 'create' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                }

                break;

            case 'addressendpoints':
            case 'address':
                if ($this->name == "Addresses" ) {
                    if ($form['action'] === 'validate' && $this->isV2()) {
                        return true;
                    }

                    if ($form['action'] === 'duplicate' || $form['action'] === 'validate' || $form['action'] == 'updateNMS') {
                        return false;
                    }
                }
                break;

            case 'cdr2':
                if (($form['action'] === 'purge' || $form['action'] === 'optimize') && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                    return false;
                }

            case 'email':
                if ($this->name == "Mailers" ) {
                    if ($form['action'] === 'transcriptioncallback') {
                        return false;
                    }
                    if ($form['action'] === 'create' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    if ($form['action'] === 'notify_unread_messages' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                }
                break;

            case 'voice':
                if ($this->name == "Voice" || $this->name == "Voices"  ) {
                    if ($form['action'] === 'transcriptionCallback') {
                        return false;
                    }
                    if ($form['action'] === 'cron' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    if ($form['action'] === 'cronUsage' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    if ($form['action'] === 'token' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    $this->loadModel('Oauthtoken');
                    if ($form['action'] === 'token' && $this->Oauthtoken->sbusIsKnownProxies($_SERVER['REMOTE_ADDR'])) {
                        return false;
                    }
                }

            case 'meeting':
                if ($this->name == "Meetings" || $this->name == "Meeting"  ) {
                    if ($form['action'] == 'register') {
                        return false;
                    }
                    if ($form['action'] == 'recordingComplete' && in_array($_SERVER["REMOTE_ADDR"], explode(",", Configure::read('SNAPhdIPs')), true)) {
                        return false;
                    }
                    if ($form['action'] == 'cron' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    if ($form['action'] == 'read' && isset($form['registration_id'])) {
                        return false;
                    }
                }
                break;

            case 'fax':
                if ($this->name == "Faxes" || $this->name == "Fax"  ) {
                    if ($form['action'] == 'cron' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                    if ($form['action'] == 'inbound' ) {
                        return false;
                    }
                }
                break;

            case 'phaxio':
                if ($this->name == "Faxes" || $this->name == "Phaxios"  ) {
                    if ($form['action'] == 'create' && isset($form['fax']) && !isset($form['phonenumber'])) {
                        return false;
                    }
                    if ($form['action'] == 'update' && isset($form['id']) && !isset($form['user'])) {
                        return false;
                    }
                }
                break;

            case 'prometheus':
                if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1") {
                    return false;
                }
                break;

            case 'push':
                if ($form['action'] == "handleBackgroundFirebase")
                {
                  if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1") {
                      return false;
                  }
                }
                break;

            case 'reseller':
                if ($this->name == "Resellers" || $this->name == "Reseller"  ) {
                    if ($form['action'] == 'insight' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                }
                break;
            case 'recording':
                if ($this->name == "Recordings" || $this->name == "Recording"  ) {
                    if ($form['action'] === 'play') {
                        return false;
                    }
                    if (strpos($form['action'], 'remote_') !== false && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return false;
                    }
                }

                break;

            case 'trace':
                if ($this->name == "Traces" || $this->name == "Trace"  ) {
                    if (($form['action'] == 'read' || $form['action'] == 'export') && isset($form['k'])) {
                        return false;
                    }
                    if (($form['action'] == 'read' || $form['action'] == 'export' || $form['action'] == 'store') && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        $this->loadModel('OauthJwt');
                        if ($this->OauthJwt->getAuthorizationHeader() == false)
                            return false; //this is admin ui. 
                    }
                }
                break;

            case 'call':
                if ($form['action'] === 'call' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                    return false;
                }
                break;
            case 'vmailnag':
                if ($form['action'] == 'update' && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                    return false;
                }
                break;
            case 'timeframe':
                if (($form['action'] == 'update' || $form['action'] == 'read' || $form['action'] == 'delete') && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1") && isset($form['isNode'])) {
                    return false;
                }
                break;
            case 'timerange':
                if (($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1") && isset($form['isNode'])) {
                    return false;
                }
                break;
            default:
                return true;
                break;
        }

        return true;
    }

    private function __checkRateLimit($token, $aco)
    {
        if (!Configure::read('NsLocalRequireOauth') || !Configure::read('NsCheckRate')) {
            return true;
        }

        $this->loadModel('Oauthstat');
        $this->Oauthstat->insertStat($token, $aco);

        return true;
    }

    function isSecure() {
      return
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $_SERVER['SERVER_PORT'] == 443;
    }


    public function indexV2()
    {
        if (array_key_exists('json', $this->request->named)) {
            $form = $this->request->named['json'];
        }
        if (array_key_exists('serialized', $this->request->named)) {
            $form = json_decode(urldecode($this->request->named['serialized']), true);
            if ($form === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->nslog('error', 'Invalid JSON in serialized parameter: ' . json_last_error_msg());
                $form = array(); // fallback to empty array
            }
        }

        $form['format'] = 'json';

        $hostIp = $_SERVER['REMOTE_ADDR'];
        $acceptedHostIps = array('127.0.0.1', '127.0.1.1', 'localhost', '::1', '192.168.139.102');
        $acceptedEmailTemplates = array('welcome_email.php', 'password_reset_email.php', 'forgotten_username_email.php');
        $this->token = array();
        $this->aco = array();

        $this->set('format', $form['format']);
        $this->set('singular', $form['singular']);
        $this->set('queryObject', @$form['object']); // I can't imagine why this wouldn't ever be set, but suppress the warning here anyway

        return $form;
    }

    public function isV2(){
        if (isset($this->request->named['apiVer']) && $this->request->named['apiVer'] == "v2")
        {
            return true;
        }
        return false;
    }

    public function index()
    {
        $this->fieldmap = new fieldmap();

        $hostIp = $_SERVER['REMOTE_ADDR'];
        $acceptedHostIps = array('127.0.0.1', '127.0.1.1', 'localhost', '::1', '192.168.139.102');
        $acceptedEmailTemplates = array('welcome_email.php', 'password_reset_email.php', 'forgotten_username_email.php');
        $this->token = array();
        $this->aco = array();
        $apiVer = "v1";



        if ($this->isV2())
        {
            //This is a simple path that previous. Will only accept json body plus full path.
            $form = $this->indexV2();
            $apiVer = "v2";
        }
        else
        {
            //This is a legacy path with accepting various parameters and formats.
            // set default form data
            $form = array();

            // get json input
            $json = file_get_contents('php://input');

            // test for data, add all datasets to form
            if (array_key_exists('form', $this->request->named)) {
                $form = $this->request->named['form'];
            }
            if (isset($this->request->data) && array_key_exists('form', $this->request->data)) {
                $form = array_merge( $form, $this->request->data['form'] );
            }
            else if (isset($this->request->data)) {
                $form = array_merge( $form, $this->request->data );
            }
            if (isset($this->request->params['form']['file'])) {
                $form['file'] = $this->request->params['form']['file'];
            }
            if ($json) {
                $decoded = json_decode($json, true);
                if ($decoded != null && is_array($decoded) && count($decoded) > 1 ) {
                    $form = array_merge( $form, json_decode($json, true) );
                }
            }
            if (isset($form['action']) && $form['action'] != "postLog")
            {
                //skip this step when posting logs as it was corupting $form
                if ($_REQUEST) {
                    $form = array_merge( $form, $_REQUEST );
                }
            }
        }





        $form['apiVer'] = $apiVer;
        $this->set('apiVer', $apiVer);

        // Provider bypass
        // ask Chris for more info ;)
        if ((!array_key_exists('action', $form) || $form['action'] === 'create') && (array_key_exists('object', $form) && $form['object'] === 'sms')) {

            // brightlink inbound if mime-boundary is found
            $pos = strpos($json, 'mime-boundary');
            if ($pos !== false) {
                $this->__ProcessBrightlinkInbound($form, $json);
            }

            // Impact and Bw.com entry
            return $this->{$form['action']}($form);
        } elseif (isset($this->request->query) && (array_key_exists('url', $this->request->query) && $this->request->query['url'] === 'sms/create')) {
            //Twillio Entry point.
            $form['action'] = 'create';
            $form['object'] = 'sms';
            $form['inboundSMS'] = true;

            return $this->{$form['action']}($form);
        }

        // set the default response format
        if ($this->request->accepts('application/json')) {
            $form['format'] = 'json';
        } else if (!array_key_exists('format', $form)) {
            $form['format'] = 'xml';
        }
        $this->set('format', $form['format']);

        $this->set('queryObject', @$form['object']); // I can't imagine why this wouldn't ever be set, but suppress the warning here anyway

        if ($this->name != "Settings")
          $this->nslog('debug', '('.$this->name.'.index) formZ '.print_r($form, true));

        // test for an action
        if (!isset($form['action'])) {
            $this->nslog('debug', '('.$this->name.'.index) Missing action. form '.print_r($form, true));

            $this->errorResponse('400 Bad Request', 'Missing action');
        }

        // test for a valid action
        if (!$this->__checkAction($form['action'])) {
            $this->nslog('debug', '('.$this->name.'.index) Action not supported. form '.print_r($form, true));
            $this->errorResponse('400 Bad Request', 'Action not supported');
        }

        // test for action === 'processAgentHours' and hostIp match an accepted hostIp
        if ($form['action'] === 'processAgentHours' && in_array($hostIp, $acceptedHostIps, true)) {
            $this->nslog('debug', '('.$this->name.'.index) REMOTE_ADDR '.print_r($_SERVER['REMOTE_ADDR'], true));
            $this->{$form['action']}($form);
        }
        // test for action === 'delete_video' and hostIp match an accepted hostIp
        elseif ($form['action'] === 'delete_video' && in_array($hostIp, $acceptedHostIps, true)) {
            $this->nslog('debug', '('.$this->name.'.index) REMOTE_ADDR '.print_r($_SERVER['REMOTE_ADDR'], true));
            $this->{$form['action']}($form);
        }
        //auth exception for account setup and recovery email templates
        elseif ($form['object'] === 'email' && isset($form['template']) && in_array($form['template'], $acceptedEmailTemplates, true)) {
            $this->nslog('debug', '('.$this->name.'.index) EMAIL_TEMPLATE '.print_r($form['template'], true));
            $this->{$form['action']}($form);
        }
        //auth exception for dashboard/chart requests that include a public_id
        elseif (($form['object'] === 'dashboard' || $form['object'] === 'chart') && $form['action'] === 'read' && isset($form['public_id']) && $form['public_id']) {
            $this->nslog('debug', '('.$this->name.'.index) PUBLIC_BOARD_REQUEST '.print_r($form['public_id'], true));
            $this->{$form['action']}($form);
        }
        //auth exception for local TTS
        elseif (($form['object'] === 'audio') && $form['action'] === 'text2speech' && in_array($hostIp, $acceptedHostIps, true)) {
            $this->nslog('debug', '('.$this->name.'.index) text2speech '.print_r($_SERVER['REMOTE_ADDR'], true));
            $this->{$form['action']}($form);
        }
        // test if there is an authorization header
        elseif ($this->__checkAuth($form)) {
            $this->loadModel('Oauthtoken');
            $this->loadModel('OauthJwt');
            $this->loadModel('Apikey');

            $isValid = false;

            $header_value = $this->OauthJwt->getHeaderToken();

            $isTokenJwt = strlen($header_value) > 40; // jwt will never be less than 40 characters
            $isApiKey = false;

            if (strlen($header_value) == 60 && substr($header_value, 0,2) == 'ns') {
                $isApiKey = true;
                $isTokenJwt = false;
            }

            if (isset($form['jwt']) && $form['object'] == "attendee" && $form['action'] == "update")
                $isTokenJwt = true;

            if ($isApiKey) { // api key
                $isValid = $this->Apikey->verifyApiKey($header_value,$this->token,$form['object'],$form['action']);
                if (isset($isValid['code']))
                    $this->errorResponse($isValid['code'], $isValid['error']);
            } else if (!$isTokenJwt) { // access_token
                $isValid = $this->Oauthtoken->verifyAccessToken($this->token);
            } else { // jwt token
                try {
                    if (isset($form['jwt']))
                      $decoded = $this->OauthJwt->getDecodedToken($form['jwt']);
                    else
                      $decoded = $this->OauthJwt->getDecodedToken();

                    $this->token = array( // construct the token object from JWT properties
                        'token'      => $header_value ,
                        'token_type' => 'access',
                        'client_id'  => @$decoded->client_id,
                        'territory'  => $decoded->territory,
                        'domain'     => $decoded->domain,
                        'department' => @$decoded->department,
                        'uid'        => $decoded->sub,
                        'expires'    => $decoded->exp,
                        'scope'      => $decoded->user_scope,
                        'mask_chain' => isset($decoded->mask_chain) && $decoded->mask_chain !== "" ? $decoded->mask_chain : ""
                    );

                    if (isset($this->token['department']) && $this->token['department'] == "")
                      unset($this->token['department']);

                    // test if refresh token has expired
                    if ($decoded->exp <= time()) {
                        $this->errorResponse(401, 'Expired token');
                    }

                    $isValid = true;
                } catch (Exception $e) {
                    $this->nslog('debug', '('.$this->name.'.index) Invalid token isSecure : '. $this->isSecure()?"yes":"no");
                    $this->nslog('error', '('.$this->name.'.index) Invalid token: '.print_r($e->getMessage(), true));
                    print_r($e->getMessage());
                    $isValid = false;
                }
            }


            if (Configure::read('NsExposePrometheus') && isset($this->token['key_id']))
            {
                try{
                    $registry = \Prometheus\CollectorRegistry::getDefault();
                    $counter = $registry->getOrRegisterCounter('api_v2_apikey', 'requests', 'requests to netsapiens api v2 path by key_id', ['key_id']);
                    $counter->incBy(1, [$this->token['key_id']]);
                }
                catch(Exception $e)
                {
                    $this->nslog('error', 'Prometheus error: '.$e->getMessage());
                }
            }



            if ($isValid) {
                // test the scope
                if ($form['object'] === 'cdrschedule' && isset($form['reseller'])) {
                    $form['territory'] = $form['reseller'];
                }

                //This gives support for ~ in user and domain fields. If ~ is used, it will be replaced with the user or domain from the token
                if (isset($form['user']) && $form['user'] == "~" && isset($this->token['uid']) && $this->token['uid'] != '*' && $this->token['uid'] != '*@*')
                    list($form['user'],$form['domain']) = explode("@", $this->token['uid']);
                else if (isset($form['domain']) && $form['domain'] == "~" && isset($this->token['domain']) && $this->token['domain'] != '*')
                {
                    $form['domain']= $this->token['domain'];
                }

                if (isset($form['uid']) && $form['uid'] == "~@~" && isset($this->token['uid']) && $this->token['uid'] != '*')
                    $form['uid']  = $this->token['uid'];

                if (isset($form['aor_user']) && $form['aor_user'] == "~" && isset($this->token['uid']) && $this->token['uid'] != '*')
                    list($form['aor_user'],$devNull) = explode("@", $this->token['uid']);
                if (isset($form['owner']) && $form['owner'] == "~" && isset($this->token['uid']) && $this->token['uid'] != '*')
                    list($form['owner'],$devNull) = explode("@", $this->token['uid']);


                if (isset($form['agent']) && $form['agent'] === "~" && isset($this->token['uid']) && $this->token['uid'] != '*')
                    $form['agent']  = $this->token['uid'];

                if (isset($form['dest_domain']) && $form['dest_domain'] == "~" && isset($this->token['domain']) && $this->token['domain'] != '*')
                    $form['dest_domain']  = $this->token['domain'];
                if (isset($form['huntgroup_domain']) && $form['huntgroup_domain'] == "~" && isset($this->token['domain']) && $this->token['domain'] != '*')
                    $form['huntgroup_domain']  = $this->token['domain'];
                if (isset($form['owner_domain']) && $form['owner_domain'] == "~" && isset($this->token['domain']) && $this->token['domain'] != '*')
                    $form['owner_domain']  = $this->token['domain'];

                if (isset($form['territory']) && $form['territory'] == "~" && isset($this->token['territory']) && $this->token['territory'] != '*')
                    $form['territory']  = $this->token['territory'];
                if (isset($form['reseller']) && $form['reseller'] == "~" && isset($this->token['territory']) && $this->token['territory'] != '*')
                    $form['reseller']  = $this->token['territory'];
                if (isset($form['scope']) && $form['scope'] == "~" && isset($this->token['scope']) && $this->token['scope'] != '*')
                    $form['scope']  = $this->token['scope'];

                if (isset($form['territory']) && $form['territory'] == "~")
                    $this->errorResponse(400, 'Unable to expand territory/reseller from token');
                if (isset($form['dest_domain']) && $form['dest_domain'] == "~")
                    $this->errorResponse(400, 'Unable to expand domain from token');

                if (isset($form['domain']) && $form['domain'] == "~" )
                    $this->errorResponse(400, 'Unable to expand domain from token');
                if (isset($form['user']) && $form['user'] == "~")
                    $this->errorResponse(400, 'Unable to expand user from token');

                if (isset($form['object']) && $form['object'] == "apikey" && !isset($form['domain']) )
                    if (isset($this->token['scope']) && ($this->token['scope'] == 'Office Manager' || $this->token['scope'] == 'Call Center Supervisor' ))
                        $form['domain'] = $this->token['domain'];

                //loop $form and check if any value is a ~
                foreach ($form as $key => $value)
                    if ($value === "~")
                        if ($key !== "key_id") //this handled by controller.
                            $this->errorResponse(400, 'Unable to expand '.$key.' from token');


                if (!$this->__checkScope($this->token, $form, $this->aco)) {
                    $this->nslog('debug', '('.$this->name.'.index) Invalid Scope form '.print_r($form, true));

                    if (isset($this->token['scope']) && $this->token['scope'] == 'Reseller') {
                        if ($this->aco['model'] == 'domain' )
                            return $this->errorResponse(404, 'Not Found');
                    }
                    $this->errorResponse(401, 'Invalid Scope [APP001]');
                }
                // test rate limit
                elseif (!$this->__checkRateLimit($this->token, $this->aco)) {
                    $this->nslog('debug', '('.$this->name.'.index) Rate Limit Exceeded form '.print_r($form, true));
                    $this->errorResponse(420, 'Rate Limit Exceeded');
                }

                if ($form['action'] === 'list') {
                    $this->list_object($form);
                } else {
                    $this->{$form['action']}($form);
                }
            } else {
                $this->nslog('debug', '('.$this->name.'.index) Invalid Token form '.print_r($form, true));
                $this->errorResponse(401, 'Invalid Token [APP011]');
            }
        } else {
            if ($form['action'] === 'list') {
                $this->list_object($form);
            } else {
                $this->{$form['action']}($form);
            }
        }

        if( $form['format'] == 'json' )
            header('Content-type: application/json');
        else
            header('Content-type: text/xml');
    }

    private function __getContentTypeHeader()
    {
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();

            if (array_key_exists('Content-Type', $headers)) {
                return $headers['Content-Type'];
            }
        }

        return false;
    }

    public function __processSBusEntry($sbusEntry)
    {
        if (empty($sbusEntry)) {
            return 'Not enough parameters';
        }

        /* Dispatch specified action, or return 404 if not found. */
        if ($this->name == 'Cdrs' && method_exists($this, 'eventUpdate')) {
            $sbusEntry['action'] = 'update';
            $this->eventUpdate($sbusEntry);

            return 'OK';
        } elseif ($this->name == 'Systemevents') {
            $this->eventUpdate($sbusEntry);
            return 'OK';
        } elseif ($this->name == 'Sqlevents') {
            $this->sqlExec($sbusEntry);
            return 'OK';
        } elseif ($this->name == 'Mailers') {
            $this->eventCreate($sbusEntry);
            return 'OK';
        } elseif ($this->name == 'Recordings' && isset($sbusEntry['cccId']) && isset($sbusEntry['status'])) {
            $this->eventCreate($sbusEntry);
            return 'OK';
        } elseif (!isset($sbusEntry['action'])) {
            if (Configure::read('NsTraceSbus')) {
                $this->nslog('debug', '('.$this->name.'.__processSBusEntry) Missing Action - form '.print_r($sbusEntry, true));
            }

            return 'Missing Action';
        }

        $strAction = strtolower($sbusEntry['action']);
        unset($sbusEntry['action']);
        if ($strAction == 'create' && method_exists($this, 'eventCreate')) {
            $this->eventCreate($sbusEntry);
        } elseif ($strAction == 'read' && method_exists($this, 'eventRead')) {
            $this->eventRead($sbusEntry);
        } elseif ($strAction == 'update' && method_exists($this, 'eventUpdate')) {
            $this->eventUpdate($sbusEntry);
        } elseif ($strAction == 'delete' && method_exists($this, 'eventDelete')) {
            $this->eventDelete($sbusEntry);
        } else {
            if (Configure::read('NsTraceSbus')) {
                $this->nslog('debug', '('.$this->name.'.event) form '.print_r($sbusEntry, true));
            }
            if (Configure::read('NsTraceSbus')) {
                $this->nslog('debug', '('.$this->name.".event) action='".$strAction."'");
            }

            return 'Action not supported';
        }

        return 'OK';
    }

    private function __processSBusBatch($sbusBatch)
    {
        $this->__processSBusEntry($sbusEntry);
    }

    private function _decodeWwwForm($strContent)
    {
        $pForm = array();

        foreach (explode('&', $strContent) as $strKeyValue) {
            $pairKeyValue = explode('=', $strKeyValue);

            if (isset($pairKeyValue[0])) {
                if (isset($pairKeyValue[1])) {
                    $pForm[urldecode($pairKeyValue[0])] = urldecode($pairKeyValue[1]);
                } else {
                    $pForm[urldecode($pairKeyValue[0])] = '';
                }
            }
        }

        return $pForm;
    }

    private function _decodeMultipartBlock($pBody)
    {
        if (empty($pBody)) {
            return false;
        }

        $pLines = preg_split("/\r\n|\n|\r/", $pBody);
        if (!isset($pLines[3])) {
            return false;
        }

        $pForm = $this->_decodeWwwForm($pLines[3]);
        $this->__processSBusEntry($pForm);

        return true;
    }

    public function event()
    {
        $form = $this->request->data;

        $this->loadModel('Oauthtoken');
        if (!$this->Oauthtoken->sbusIsKnownProxies($_SERVER['REMOTE_ADDR'])) {
            if (Configure::read('NsTraceSbus')) {
                $this->nslog('debug', '('.$this->name.'.event) Unauthorized form '.print_r($form, true));
            }

            $this->errorResponse(401, 'Unauthorized');
        }

        // read incoming data
        $input = file_get_contents('php://input');

        // grab multipart boundary from content type header
        preg_match('/boundary=(.*)$/', $_SERVER['CONTENT_TYPE'], $matches);
        if (!count($matches)) {
            $strResult = $this->__processSBusEntry($form);
            if ($strResult == 'OK') {
                $this->nullResponse();
            } else {
                $this->errorResponse(404, $strResult);
            }
        } else {
            $boundary = $matches[1];

            // split content by boundary and get rid of last -- element
            $pBlocks = preg_split("/-+$boundary/", $input);
            array_pop($pBlocks);

            // loop data blocks
            foreach ($pBlocks as $id => $pBlock) {
                $this->_decodeMultipartBlock($pBlock);
            }
            $this->nullResponse();
        }
    }

    public function GetTzTime($strGmtTime, $strTimeZone, $strFormat)
    {
        $dateTimeZone = new DateTimeZone($strTimeZone);
        $dateTime = new DateTime($strGmtTime, $dateTimeZone);
        $timeOffset = $dateTimeZone->getOffset($dateTime);

        $strModify = strval($timeOffset).' second';

        $dateTime->modify($strModify);

        return $dateTime->format($strFormat);
    }

    public function getRawNumber($phoneNumber, $domain="*", $user="*") {
        require_once realpath(__DIR__.'/../Vendor/autoload.php');

        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();

        if (!isset($this->Uiconfig)) {
            $this->loadModel('Uiconfig');
        }

        $country = $this->Uiconfig->Query('PORTAL_LOCALIZATION_NUMBER_FORMAT', 'US',/*$strLoginType = */ '*', /*$strDomain = */ $domain, /*$strRole =*/  '*', /*$strUser = */ $user);
        if ($country == "") {
            $country='US';
        }

        $international = $phoneUtil->parse($phoneNumber, $country);//Throws an exception if number is not international
        // $this->nslog('messaging', 'this is international: '.print_r($international, true));
        // $this->nslog('messaging', 'this is $international->getCountryCode(): '.print_r($international->getCountryCode(), true));
        // $this->nslog('messaging', 'this is $international->getNationalNumber(): '.print_r($international->getNationalNumber(), true));

        return $international->getCountryCode().$international->getNationalNumber();
    }

    public function formatNumber($phoneNumber, $domain="*", $user="*")
    {
        //equire_once '../Vendor/giggsey/libphonenumber-for-php ';
        require_once realpath(__DIR__.'/../Vendor/autoload.php');
        //include '../Vendor/giggsey/libphonenumber-for-phpzapcallib.php';
        //echo $phoneNumber;
        if (!isset($this->Uiconfig)) {
            $this->loadModel('Uiconfig');
        }

        if ($this->Uiconfig->isUiConfig('PORTAL_LOCALIZATION_NUMBER_FORMAT_MASTER', 'yes')) {

            $country = $this->Uiconfig->Query('PORTAL_LOCALIZATION_NUMBER_FORMAT', 'US',/*$strLoginType = */ '*', /*$strDomain = */ $domain, /*$strRole =*/  '*', /*$strUser = */ $user);
            if ($country == "")
              $country='US';
        } else {
            return $phoneNumber;
        }

        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
        try {
            //$this->nslog('debug', '(PhonenumberHelper.formatNumber) phoneNumber '.$phoneNumber);

            $international = $phoneUtil->parse($phoneNumber, null);//Throws an exception if number is not international

            //$this->nslog('debug', '(PhonenumberHelper.formatNumber) international '.$international);
            if ($phoneUtil->isPossibleNumber($international)) {
                $formatted = $phoneUtil->format($international, \libphonenumber\PhoneNumberFormat::E164);
                //$this->nslog('debug', '(PhonenumberHelper.formatNumber) formatted '.$formatted);
                return $formatted;
            }
            else {
                return $phoneNumber;
            }
        } catch (\libphonenumber\NumberParseException $e) {
            try {
                $national = $phoneUtil->parse($phoneNumber, $country);
                //$this->nslog('debug', '(PhonenumberHelper.formatNumber) national '.$national);
                if ($phoneUtil->isPossibleNumber($national)) {
                    $formatted = $phoneUtil->format($national, \libphonenumber\PhoneNumberFormat::NATIONAL);
                    //$this->nslog('debug', '(PhonenumberHelper.formatNumber) formatted '.$formatted);
                    return $formatted;
                }
                else
                  return $phoneNumber;
            }
            catch (\libphonenumber\NumberParseException $e) {
                return $phoneNumber;
            }
        }
    }

    public function formatPhoneNumber($strPhoneNumber, $domain="*", $user="*")
    {
        if (!isset($this->Uiconfig)) {
            $this->loadModel('Uiconfig');
        }

        $strPhoneNumber = urldecode($strPhoneNumber);
        $strPhoneNumber = str_replace(' ', '', $strPhoneNumber);


        $pos = strpos($strPhoneNumber, 'adhoc.monitor');
        if ($pos === false) {
        } else {
            return 'Audio Monitoring';
        }

        $pos = strpos($strPhoneNumber, 'video.bridge');
        if ($pos === false) {
        } else {
            return 'VideoBridge';
        }

        $strPhoneNumber = str_replace('sip:', '', $strPhoneNumber);
        $strPhoneNumber = str_replace('tel:', '', $strPhoneNumber);
        $strPhoneNumber = str_replace('TEL', '', $strPhoneNumber);
        $strPhoneNumber = str_replace('sip', '', $strPhoneNumber);
        $strPhoneNumber = str_replace('+', '', $strPhoneNumber);
        $strPhoneNumber = str_replace('%40', '@', $strPhoneNumber);


        $pos = strpos($strPhoneNumber, '@');
        if ($pos === false) {
        } else {
            $strPhoneNumber = substr($strPhoneNumber, 0, $pos);
        }


        $strReturn = '';
        if (!is_numeric($strPhoneNumber)) {
            if ($strPhoneNumber == CALL_REQUEST_CALL_RECORD_GREETING) {
                $strReturn = DISPLAY_CALL_RECORD_GREETING;
            } elseif ($strPhoneNumber == CALL_REQUEST_CALL_RECORD_NAME) {
                $strReturn = DISPLAY_CALL_RECORD_NAME;
            } elseif ($strPhoneNumber == CALL_REQUEST_CALL_RECORD_REPLY) {
                $strReturn = DISPLAY_CALL_RECORD_REPLY;
            } elseif ($strPhoneNumber == CALL_REQUEST_CALL_SAY_TIME) {
                $strReturn = DISPLAY_CALL_SAY_TIME;
            } elseif ($strPhoneNumber == CALL_REQUEST_CALL_TO_TALK) {
                $strReturn = DISPLAY_CALL_TO_TALK;
            } else {
                $strReturn = $strPhoneNumber;
            }
        } elseif (strncmp($strPhoneNumber, '+', 1) == 0) {
            // Generic International with single "+" prefix
            $strReturn = $strPhoneNumber;
        } elseif ($this->Uiconfig->isUiConfig('PORTAL_PHONENUMBER_REPLACE_PLUSSIGN', 'yes') && (strncmp($strPhoneNumber, '011', 3) == 0) && (strlen($strPhoneNumber) > 7)) {
            $strReturn .= '+';
            $strReturn .= substr($strPhoneNumber, 3);
        } elseif ($this->Uiconfig->isUiConfig('PORTAL_PHONENUMBER_REPLACE_PLUSSIGN', 'yes') && (strncmp($strPhoneNumber, '00', 2) == 0) && (strncmp($strPhoneNumber, '000', 3) != 0) && (strlen($strPhoneNumber) > 7)) {
            $strReturn .= '+';
            $strReturn .= substr($strPhoneNumber, 2);
        } elseif (strlen($strPhoneNumber) == 11) {
            // Generic Domestic Long Distance with single "0" prefix
            if ((strncmp($strPhoneNumber, '0', 1) == 0) && (strncmp($strPhoneNumber, '00', 2) != 0)) {
                $strReturn .= substr($strPhoneNumber, 1, 2);
                $strReturn .= ' ';
                $strReturn .= substr($strPhoneNumber, 3, 4);
                $strReturn .= ' ';
                $strReturn .= substr($strPhoneNumber, 7);
            } elseif (strncmp($strPhoneNumber, '1', 1) == 0) {
                // US Domestic Long Distance 11-Digit
                $strReturn .= substr($strPhoneNumber, 0, 1);
                $strReturn .= ' (';
                $strReturn .= substr($strPhoneNumber, 1, 3);
                $strReturn .= ') ';
                $strReturn .= substr($strPhoneNumber, 4, 3);
                $strReturn .= '-';
                $strReturn .= substr($strPhoneNumber, 7, 4);
            } else {
                $strReturn = $strPhoneNumber;
            }
        } elseif (strlen($strPhoneNumber) == 10) {
            if ((strncmp($strPhoneNumber, '0', 1) != 0) && (strncmp($strPhoneNumber, '1', 1) != 0)) {
                // US Domestic Long Distance 10-Digit
                $strReturn .= '(';
                $strReturn .= substr($strPhoneNumber, 0, 3);
                $strReturn .= ') ';
                $strReturn .= substr($strPhoneNumber, 3, 3);
                $strReturn .= '-';
                $strReturn .= substr($strPhoneNumber, 6, 4);
            } else {
                $strReturn = $strPhoneNumber;
            }
        } elseif (strlen($strPhoneNumber) == 8) {
            if (strncmp($strPhoneNumber, '0', 1) != 0) {
                $strReturn .= substr($strPhoneNumber, 0, 4);
                $strReturn .= ' ';
                $strReturn .= substr($strPhoneNumber, 4);
            } else {
                $strReturn = $strPhoneNumber;
            }
        } elseif (strlen($strPhoneNumber) == 7) {
            // US Domestic Local Call 7-Digit
            if (strncmp($strPhoneNumber, '0', 1) != 0) {
                $strReturn .= substr($strPhoneNumber, 0, 3);
                $strReturn .= '-';
                $strReturn .= substr($strPhoneNumber, 3, 4);
            } else {
                $strReturn = $strPhoneNumber;
            }
        } else {
            $strReturn = $strPhoneNumber;
        }

        return $this->formatNumber($strReturn,$domain, $user);
    }

    /*
        For v2, will pass in aor, and adjust the transcription and sentiment settings
        For domain aor is *@domainName
        For device aor is sip:1123wp@portal
        For user aor is 1123@portal
        FOr huntgroup aor is 25004@portal
    */
    public function updateRecording($toUpdate) {
        $this->nslog('debug', '('.$this->name.'.updaterecording) toUpdate '.print_r($toUpdate, true));

        if (isset($toUpdate['aor'])) {
        } else {
            $this->errorResponse(400, 'Please submit "aor" parameter');
        }

        $this->loadModel('Lic');
        $pServers = $this->__QueryRecordingServers();
        foreach ($pServers as $pServer) {

            $query['lea_id'         ] = $pServer;
            $query['device_aor'     ] = $toUpdate['aor'];
            $query['case_id'        ] = $toUpdate['aor'];

            if ($toUpdate['action'] == 'delete') {
                // if set to "no", delete lic
                $this->addAuditlogInfo($query, $toUpdate['aor']);

                $this->nslog('debug', '('.$this->name.'.updaterecording delete) query '.print_r($query, true));

                $this->addAuditlogInfo($query, $toUpdate['aor']);
                if (!$this->Lic->nsDelete($query,  'lidf_events', 'lic')) {
                    $this->errorResponse(400, 'Fail to delete LIC');
                }
                continue;
            }

            $query['valid_from'     ] = '0000-00-00 00:00:00';
            $query['valid_to'       ] = '0000-00-00 00:00:00';
            $query['ccc_intcpt'     ] = 'yes';
            $query['cdc_intcpt'     ] = 'yes';
            if (isset($toUpdate['transcription'])) {
                $query['transcription'  ] = $toUpdate['transcription'];
            }
            if (isset($toUpdate['sentiment'])) {
                $query['sentiment'      ] = $toUpdate['sentiment'];
            }

            $this->nslog('debug', 'updateRecording query: '.print_r($query, true));
            $this->addAuditlogInfo($query, $toUpdate['aor']);
            if (!$this->Lic->nsCreate($query,  'lidf_events', 'lic')) {
                $this->errorResponse(400, 'Fail to create LIC');
            }
        }
    }

    public function __QueryRecordingServers()
    {
        $pRows = array();

        $sql = 'SELECT';
        $sql .= ' lea_id';
        $sql .= ' FROM '.LIDF_LEA_TABLE;
        $sql .= ' WHERE';
        $sql .= " lea_id LIKE 'Recording-Server%'";
        $sql .= ' ORDER BY lea_id ASC';

        if (!isset($this->Lic)) {
            $this->loadModel('Lic');
        }
        $pRows = $this->Lic->query($sql);

        $results = array();
        foreach ($pRows as $pRow) {
            $results[] = $pRow[LIDF_LEA_TABLE]['lea_id'];
        }

        return $results;
    }

    public function readRecordingConfiguration($aor) {
        // read recording settings
        $pServers = $this->__QueryRecordingServers();
        $recording = '';
        $transcription = '';
        $sentiment = '';
        $recordingStringFinal = '';
        foreach ($pServers as $pServer) {
            $recordingQuery['lea_id'] = $pServer;
            $recordingQuery['device_aor'] = $aor;
            // $query['case_id'] = '*@' . $form['domain'];
            $recordingQuery['valid_from'] = '0000-00-00 00:00:00';
            $recordingQuery['valid_to'] = '0000-00-00 00:00:00';
            $recordingQuery['ccc_intcpt'] = 'yes';
            $recordingQuery['cdc_intcpt'] = 'yes';
            $recordingQuery['object'] = 'lic';
            $this->nslog('debug', '('.$this->name.'.read) lics query'.print_r($recordingQuery, true));

            $lics = $this->Lic->nsRead($recordingQuery);
            $this->nslog('debug', '('.$this->name.'.read) lics '.print_r($lics, true));
            // check if lic is set, if it is set to transcription "no" then set to no

            if (isset($lics['xml']['lic'][0])) {
                $recording = 'yes';
            }

            if (isset($lics['xml']['lic'][0]) && $lics['xml']['lic'][0]['transcription'] == 'yes') {
                $transcription = 'yes';
            }

            if (isset($lics['xml']['lic'][0]) && $lics['xml']['lic'][0]['sentiment'] == 'yes') {
                $sentiment = 'yes';
            }
        }

        // build the recording string
        if ($recording == 'yes') {
            $recordingStringFinal .= 'yes';
        } else {
            $recordingStringFinal = 'no';
        }
        if ($transcription == 'yes') {
            $recordingStringFinal .= '-with-transcription';
        }
        if ($sentiment == 'yes') {
            $recordingStringFinal .= '-and-sentiment';
        }

        return $recordingStringFinal;
    }


    public function NsLogError($action, $error_text, $deep_trace = '')
    {
        if (!isset($this->Errorlog)) {
            $this->loadModel('Errorlog');
        }
        $client_id = '';
        if (isset($_SESSION['client_id'])) {
            $client_id = $_SESSION['client_id'];
        }
        if (Configure::read('NsErrorLogDeep')) {
            $this->Errorlog->insertLog($client_id, $this->name, $action, $deep_trace.' '.$error_text);
        } else {
            $this->Errorlog->insertLog($client_id, $this->name, $action, $error_text);
        }
    }

    public function NsAuditLog($action, $error_text, $deep_trace = '')
    {
        if (!isset($this->Auditlog)) {
            $this->loadModel('Auditlog');
        }
        $client_id = '';
        if (isset($_SESSION['client_id'])) {
            $client_id = $_SESSION['client_id'];
        }

        $this->Auditlog->insertLog($client_id, $this->name, $action, $error_text);
    }

    public function sendOriginHeaders() {
        $match = false;

        if (Configure::read('NsAllowedOriginHostnames') != null && isset($_SERVER['HTTP_REFERER'])) {
            $httpReferer = rtrim($_SERVER['HTTP_REFERER'], "/");

            if ($httpReferer) {
                $urlParse = parse_url($httpReferer);
                $httpOrigin = $urlParse['scheme'] . '://' . $urlParse['host'];

                $configHostnames = Configure::read('NsAllowedOriginHostnames');
                $allowedOriginHostnames = explode(',', $configHostnames);

            foreach ($allowedOriginHostnames as $hostname) {
                if ($httpOrigin === trim($hostname)) {
                    $match = $httpOrigin;
                    }
                }
            }
        }

        if ($match) {
            header('Access-Control-Allow-Origin: ' . $match);
            header('Access-Control-Allow-Credentials: ' . "true");
        } else {
            header('Access-Control-Allow-Origin: ' . "*");
        }
    }

    public function nullResponse($http_status_code = 200, $forcev2 = false)
    {
        header("HTTP/1.1 $http_status_code");
        header('X-Powered-By: netsapiens');


        if ((isset($this->request->url) && substr( $this->request->url, 0, 3 ) === "v2/") || (@$this->request->params['named']["apiVer"]==  "v2") || $forcev2)
        {
            header('Content-type: application/json');
            if ($http_status_code == 202)
                $status_body = array('code' => $http_status_code,'message' => "Accepted");
            else
                $status_body = array('code' => $http_status_code,'message' => "Success");
            echo json_encode($status_body);
        }
        else
        {
            header('Content-type: text/plain');
        }

        exit;
    }

    public function errorResponse($http_status_code, $error_description = null)
    {
        if (Configure::read('NsExposePrometheus'))
        {
            try {
                $registry = \Prometheus\CollectorRegistry::getDefault();
                $counter = $registry->getOrRegisterCounter('api_v2', 'error', 'requests to netsapiens api resulting in errors', ['code','message']);
                $counter->incBy(1, [$http_status_code,@substr($error_description,0,32)]);
            }
            catch(Exception $e)
            {
                $this->nslog('error', 'Prometheus error: '.$e->getMessage());
            }
            
        }
        if ((isset($this->request->url) && substr( $this->request->url, 0, 3 ) === "v2/") ||
            (@$this->request->params['named']["apiVer"]==  "v2") || (substr( @$this->request->query['url'], 0, 3 ) == "v2/"))
        {
            header("HTTP/1.1 {$http_status_code}. $error_description");
            header('X-Powered-By: netsapiens');
            header('Content-type: application/json');
            if ($error_description) header("Warning: $error_description");

            $error_body = array('code' => $http_status_code,'message' => $error_description);
            // if (isset($this->request->url))
            //     $error_body['request'] = $this->request->url;
            echo json_encode($error_body);
        }
        else
        {
            header("HTTP/1.1 {$http_status_code}. $error_description");
            header('X-Powered-By: netsapiens');
            header('Content-type: text/plain');
            if ($error_description) header("Warning: $error_description");
        }

        exit;
    }

    public function responseWithXml($result = null)
    {
        header('HTTP/1.1 200 OK');
        header('X-Powered-By: netsapiens');
        header('Content-type: text/xml; charset="utf-8"');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        if ($result) {
            try {
                $xml = Xml::build($result);
            } catch (XmlException $e) {
                throw new InternalErrorException();
            }
            echo $xml->saveXML();
        }

        exit;
    }

    public function responseWithRfc1738($result = null)
    {
        header('HTTP/1.1 200 OK');
        header('X-Powered-By: netsapiens');
        header('Content-Type: application/x-www-form-urlencoded');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        if ($result) echo http_build_query($result);

        exit;
    }

    public function responseWithJson($result = null)
    {
        header('HTTP/1.1 200 OK');
        header('X-Powered-By: netsapiens');
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        if ($result) echo json_encode($result);

        exit;
    }

    public function responseWithJsonNoExit($result = null)
    {
        header('HTTP/1.1 200 OK');
        header('X-Powered-By: netsapiens');
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        if ($result) echo json_encode($result);
    }

    public function GetPagintaion($form, &$conditions = null)
    {
        if ($conditions == null) {
            $conditions = array();
        }
        if (isset($form['start'])) {
            $conditions['start'] = $form['start'];
        }
        if (isset($form['limit'])) {
            $conditions['limit'] = $form['limit'];
        }
        if (isset($form['sort'])) {
            $conditions['sort'] = $form['sort'];
        }

        return $conditions;
    }

    public function _printMS($start, $end)
    {
        return intval(1000 * ($end - $start));
    }

    public function checkUserCredentials(&$infoUser, $tokenScope = false, $sendEmail = true)
    {
        $start = microtime(true);

        $query['conditions']['subscriber_login'] = $infoUser['username'];
        $query['fields'] = 'aor_user,aor_host,firstname,lastname,subscriber_login,subscriber_group,subscriber_pin,scope,pwd_hash,email_address,site';

        $this->loadModel('Subscriber');
        $result = $this->Subscriber->find('first', $query);

        if (!isset($result['Subscriber'])) {
            return false;
        } elseif (sizeof($result['Subscriber']) == 0) {
            return false;
        }

        $this->loadModel('Domain');
        $infoUser['user'] = $result['Subscriber']['aor_user'];
        $infoUser['territory'] = $this->Domain->getTerritory($result['Subscriber']['aor_host']);
        $infoUser['domain'] = $result['Subscriber']['aor_host'];
        $infoUser['site'] = $result['Subscriber']['site'];
        $infoUser['group'] = $result['Subscriber']['site'];
        $infoUser['department'] = $result['Subscriber']['subscriber_group'];
        $infoUser['uid'] = $result['Subscriber']['aor_user'].'@'.$result['Subscriber']['aor_host'];
        $infoUser['login'] = $result['Subscriber']['subscriber_login'] ;
        $infoUser['scope'] = $result['Subscriber']['scope'];
        $infoUser['user_email'] = $result['Subscriber']['email_address'];
        $infoUser['displayName'] = trim($result['Subscriber']['firstname'] . ' ' . $result['Subscriber']['lastname']);

        $end = microtime(true);

        if (Configure::read('NsTraceOauth')) {
            $this->nslog('debug', '('.$this->name.'.__checkUserCredentials) time='.$this->_printMS($start, $end).'ms');
        }

        $hashedPwd = $result['Subscriber']['pwd_hash'];

        if (strlen($hashedPwd) !== 60) {
            $hashedPwd = null;
        }

        $voicemailPin = $result['Subscriber']['subscriber_pin'];

        if (password_verify($infoUser['password'], $hashedPwd)) {
            return true; // return true if secure creds are valid
        }
        // if vmpin is valid && pwd_hash isnt set but we're look for secure creds...
        elseif ($infoUser['password'] == $voicemailPin && !$hashedPwd) {

            // if we're in the configed legacy window...
            if (Configure::read('LegacySecurity') && $this->daysRemainingInLegacy() > 0) {
                $infoUser['legacy'] = true;
                return true;
            }

            // if the subscriber does not have an email
            if(!$result['Subscriber']['email_address']) {
                $infoUser['recover'] = false;
                $infoUser['scope'] = '';
                return true;
            }

            // test if this call is for the portal
            if($tokenScope === 'portal') {
                $infoUser['recover'] = true;
                $infoUser['scope'] = '';
                return true;
            }

            if($tokenScope === 'video') {
                return true;
            }

            // test if this call is for webphone
            if($tokenScope === 'webphone') {
                $infoUser['recover'] = true;
                $infoUser['scope'] = '';

                if(!$sendEmail) {
                    return true;
                }

                if (!isset($this->Uiconfig)) {
                    $this->loadModel('Uiconfig');
                }

                $userId = null;
                if (array_key_exists('uid', $infoUser)) {
                    $userId = explode('@', $infoUser['uid']);
                    $userId = $userId[0];
                } else {
                    $userId = null;
                }

                $appName = null;
                $pathname = null;
                if($tokenScope === 'webphone') {
                    $appName = $this->Uiconfig->Query(
                        'PORTAL_WEBPHONE_NAME',
                        'SNAPmobile Web',
                        $strLoginType = '*',
                        $strDomain = $infoUser['domain'],
                        $strRole = '*',
                        $strUser = $userId
                    );
                    $pathname = 'webphone';
                }
                /*else if ($tokenScope === 'video') {
                    $appName = $this->Uiconfig->Query(
                        'PORTAL_VIDEO_NAME',
                        'Video Meeting',
                        $strLoginType = '*',
                        $strDomain = $infoUser['domain'],
                        $strRole = '*',
                        $strUser = $userId
                    );
                    $pathname = 'video';
                }*/

                App::import('Controller', 'Mailers');
                $Mailers = new MailersController;
                $Mailers->create(array(
                    'client_id' => $_SESSION['client_id'],
                    'sender'    => $this->Domain->getSenderEmail($result['Subscriber']['aor_host']),
                    'username'  => $result['Subscriber']['subscriber_login'],
                    'recipient' => $result['Subscriber']['email_address'],
                    'subject'   => 'Update your ' . $appName . ' password',
                    'template'  => 'password_reset_email.php',
                    'app_uri'  => 'https://' . gethostname() . '/' . $pathname . '/?username=<USERNAME>&auth_code=<AUTH_CODE>' )
                );

                return true;
            }

            $infoUser['recover'] = true;
            $infoUser['scope'] = '';

            if(!$sendEmail) {
                return true;
            }

            App::import('Controller', 'Mailers');
            $Mailers = new MailersController;
            $Mailers->create(array(
                'client_id' => $_SESSION['client_id'],
                'sender'    => $this->Domain->getSenderEmail($result['Subscriber']['aor_host']),
                'username'  => $result['Subscriber']['subscriber_login'],
                'recipient' => $result['Subscriber']['email_address'],
                'subject'   => 'Update your portal password',
                'template'  => 'password_reset_email.php' )
            );

            return true;
        }

        return false;
    }

    public function daysRemainingInLegacy()
    {
        $startDate = strtotime(Configure::read('LegacySecurity.StartDate'));
        $daysUntilLockout = Configure::read('LegacySecurity.DaysUntilLockout');
        $endDate = $startDate + ($daysUntilLockout * 24 * 60 * 60);

        $remainingTimeInWindow = $endDate - time();
        return round($remainingTimeInWindow / 24 / 60 / 60); // days
    }

    public function addAuditlogInfo(&$form, $id = null, $a = null, $m=null)
    {
        // 'time_stamp,by_domain,by_user,by_client,action,target_domain,target_user,target_object,target_host';
        if (isset($this->token['uid'])) {
            if (!isset($this->token['mask_chain']) && isset($this->token['key_id'])) {
                $form['audit_log']['by_user'] = $this->token['key_id'];
            } else if (isset($this->token['mask_chain']) && $this->token['mask_chain'] != '') {
                $form['audit_log']['by_user'] = $this->token['mask_chain'] != null ?
                    $this->token['mask_chain'] . ' [mask] ' . $this->token['uid']:
                    $this->token['uid'];
            } else {
                $form['audit_log']['by_user'] = $this->token['uid'];
            }
        }
        elseif (isset($this->token['key_id']))
            $form['audit_log']['by_user'] = $this->token['key_id'];
        if (isset($this->token['domain'])) {
            $form['audit_log']['by_domain'] = $this->token['domain'];
        }
        if (isset($this->token['client_id'])) {
            $form['audit_log']['by_client'] = $this->token['client_id'];
        }

        if (isset($_SERVER['HTTP_X_NETSAPIENS_REMOTE_ADDR'])) {
            $form['audit_log']['by_ip'] = $_SERVER['HTTP_X_NETSAPIENS_REMOTE_ADDR'];
        } else {
            $form['audit_log']['by_ip'] = $_SERVER['REMOTE_ADDR'];
        }

        if ($id != null) {
            $fulluser = explode('@',$id);
            if (count($fulluser) == 2) {
                $form['audit_log']['target_user'] = $fulluser[0];
                $form['audit_log']['target_domain'] = $fulluser[1];
            } else {
                $form['audit_log']['target_user'] = $id;
            }
        } elseif (isset($this->aco['user'])) {
            $form['audit_log']['target_user'] = $this->aco['user'];
        }

        if (isset($this->aco['domain'])) {
            $form['audit_log']['target_domain'] = $this->aco['domain'];
        }

        if ($m != null)
        {
          $form['audit_log']['target_object'] = $m;
        }
        elseif (isset($this->aco['model'])) {
            $form['audit_log']['target_object'] = $this->aco['model'];
        }

        if ($a != null) {
            $form['audit_log']['action'] = $a;
        } elseif (isset($this->aco['action'])) {
            $form['audit_log']['action'] = $this->aco['action'];
        }

        $this->nslog('auditlog', print_r($form['audit_log'], true));

        return $form;
    }

    private function __getAcoModel(&$aco, $form)
    {
        if (isset($form['object'])) {
            switch ($form['object']) {
                default:
                    $aco['model'] = $form['object'];
                    break;
            }

            return true;
        } else {
            if (Configure::read('NsTraceScope')) {
                $this->nslog('debug', '('.$this->name.'.checkScope.__getAcoModel) missing object and return FALSE');
            }

            return false;
        }
    }

    private function __IsModelUnscoped($aco, $form = null)
    {

        if (isset($_SERVER['HTTP_X_NETSAPIENS_REMOTE_ADDR'])) {
            $accessingIP =  $_SERVER['HTTP_X_NETSAPIENS_REMOTE_ADDR'];
        } else {
            $accessingIP =  $_SERVER['REMOTE_ADDR'];
        }
        switch ($aco['model']) {
            case 'apikey':
                if ( ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1" )) {
                    return true;
                }
                break;
            case 'holiday':
                if ($this->name == "Timeframes" ) {
                    return true;
                }
                
                break;


            case 'conference':
                if ($this->name == "Conference" || $this->name == "Conferences" ) {
                    if (($aco['action'] == 'create' || $aco['action'] == 'count' || $aco['action'] == 'list' || $aco['action'] == 'read')
                    && (isset($form['aor']) && strpos($form['aor'], 'video') !== false)) {
                        if ($form['domain'] == $aco['domain']) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) '.print_r($form, true));
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');

                            return true;
                        }
                    }
                }
                break;

            case 'connection':
                if ($this->name == "Connections" ) {
                    if ($aco['action'] == 'list' && $form['action'] == 'count'  && isset($form['aor'])) {
                        return true;
                    }
                }
                
                break;

            case 'participant':
                if ($this->name == "Conference" || $this->name == "Conferences" ) {
                    $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped participant form) '.print_r($form, true));
                    $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped participant aco) '.print_r($aco, true));
                    if (($aco['action'] == 'count' || $aco['action'] == 'read') && strpos($form['conference_match'], 'video.bridge') !== false) {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped participant ) '.print_r($form, true));
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        return true;

                    }
                }
                break;


            case 'device':
                if ($this->name == "Devices" || $this->name == "Device" ) {
                    $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped device form) '.print_r($form, true));
                    $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped device aco) '.print_r($form, true));
                    if (($aco['action'] == 'create' || $aco['action'] == 'count' || $aco['action'] == 'read')
                    && isset($form['device']) &&  strpos($form['device'], 'guest_') !== false && strpos($form['device'], 'vb@') !== false)
                    {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped device guest) ');
                        if ($form['domain'] == $aco['domain'] && $form['user'] ==  "guest") {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped device user) ');
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) '.print_r($form, true));
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');

                            return true;
                        }
                    }
                    if (isset($form['form']) && $form['form']['action'] == 'cron') {
                        if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")
                        return true;
                    }
                }
                break;

            case 'sfu':
                if ($this->name == "Sfus" || $this->name == "Sfu" ) {
                    if ($aco['action'] == 'create' && strpos($_REQUEST['uid'], 'guest_') !== false && isset($_REQUEST['room_id']) && strlen($_REQUEST['room_id'])>5  )
                    {
                    return true;
                    }
                }
                break;

            case 'audio':
                if ($this->name == "Musics" || $this->name == "Music" ) {
                    if ($form['action'] == 'play') {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }

                        return true;
                    }
                    if ($form['action'] == 'link_delete' || $form['action'] == 'link_update') {
                        return true;
                    }
                    if (strpos($form['action'], 'remote_') !== false && ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")) {
                        return true;
                    }
                }

                break;

            case 'cdrexport':
                if ($this->name == "CdrExport" || $this->name == "Cdrexport" || $this->name == "CdrExports" ) {
                    if ($form['action'] == 'download') {
                    return true;
                    }
                }
                break;

            case 'video':
                if ($this->name == "Videos" || $this->name == "Video"  ) {
                    if ($aco['action'] == 'signin') {
                        return true;
                    }
                }
                break;

            case 'address':
                if ($this->name == "Addresses" || $this->name == "Address"  ) {
                    if ($form['action'] == 'duplicate' || $form['action'] == 'updateNMS') {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) address duplicate return TRUE');
                    return true;
                    }
                }
                break;
            case 'domain':
                if ($this->name == "Domains" || $this->name == "Domain"  ) {
                    if ($aco['action'] == 'list' && isset($form['checkOnly'])) {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped aco ) '.print_r($aco, true));
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped form) '.print_r($form, true));

                        return true;
                    }
                }
                break;

            case 'ndpserver':
            case 'ndpserverList':
            case 'devicemodel':
                if ($this->name == "Devices" || $this->name == "Device" ) {
                    if (Configure::read('NsTraceScope')) {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                    }
                    return true;
                }
                break;

            case 'timezone':
                if ($this->name == "Subscriber" || $this->name == "Subscribers" ) {
                    if (Configure::read('NsTraceScope')) {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                    }
                    return true;
                }
                
                break;

            case 'mac':
                if ($this->name == "Devices" || $this->name == "Device" ) {
                    if ($aco['action'] == 'read') {
                        if (isset($form['checkExistance'])) {
                            if (Configure::read('NsTraceScope')) {
                                $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                            }

                            return true;
                        }
                    }
                }
                break;

            case 'message':
                if ($this->name == "Messages" || $this->name == "Message" ) {
                    if (isset($form['form']) && $form['form']['action'] == 'read_media') {
                        $this->nslog('messaging', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        return true;
                    }
                    if (isset($form['form']) && $form['form']['action'] == 'create') {
                        $this->nslog('messaging', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        return true;
                    }
                }
                
                break;
            case 'call':
                if ($this->name == "Calls" || $this->name == "Call" ) {
                    if (isset($form['form']) && $form['form']['action'] == 'call') {
                        $this->nslog('messaging', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        return true;
                    }
                }
                break;
            case 'messagesession':
                if ($this->name == "Messages" || $this->name == "Message" ) {
                    if ($aco['action'] == 'update' && isset($form['last_status'])&& isset($form['session_id'])) {
                        if (strpos($form['session_id'], "private") !==false && strlen($form['session_id']) > 20)
                        return true;
                        if (strpos($form['session_id'], "hosts") !==false && strlen($form['session_id']) > 20)
                        return true;
                        if (strpos($form['session_id'], "meeting") !==false && strlen($form['session_id']) > 20)
                        return true;
                    }
                }
                break;

            case 'meeting':
                if ($this->name == "Meetings") {
                    if (isset($form['form']) && $form['form']['action'] == 'cron') {
                        if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")
                        return true;
                    }
                }
                break;


            case 'sms':
                if ($this->name == "Messages" || $this->name == "Message" ) {
                    if ($aco['action'] == 'create' && isset($form['inboundSMS']) && $form['inboundSMS']) {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }

                        return true;
                    }
                    if ($aco['action'] == 'notifications')
                    return true;

                    if ($aco['action'] == 'processQueuedSMS' && $accessingIP === "127.0.0.1")
                    return true;
                }

                return false;
                break;

            case 'fax':
                if (isset($form['form']) && $form['form']['action'] == 'cron') {
                    if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")
                        return true;
                }
                if ($this->name == "Faxes" || $this->name == "Fax" ) {
                    if (isset($form['form']) && $form['form']['action'] == 'inbound') {
                        return true;
                    }
                }
                break;

            case 'push':
                if ($form['action'] == "handleBackgroundFirebase")
                {
                  if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1") {
                      return true;
                  }
                }
                return false;
                break;

            case 'phaxio':
                if ($this->name == "Faxes" || $this->name == "Fax" || $this->name == "Phaxios" ) {
                    if ($aco['action'] == 'create') {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }

                        return true;
                    }
                }

                return false;
                break;

            case 'prometheus':
                if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")
                  return true;

                return false;
                break;

            case 'videoguest':
                if ($this->name == "Videos" || $this->name == "Video"  ) {
                    if ($aco['action'] == 'create' || $aco['action'] == 'read') {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }

                        return true;
                    }
                }

                return false;
                break;

            case 'email':
                if ($this->name == "Mailers"  ) {
                    if (isset($form['action']) && $form['action'] == 'transcriptioncallback') {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }

                        return true;
                    }
                }

                return false;
                break;

            case 'cdrschedule':
                if ($this->name == "CdrSchedules" || $this->name == "CdrSchedule" || $this->name == "Cdrschedules"  ) {
                    if ((isset($form['action']) && ($form['action'] == 'update' || $form['action'] == 'read')) && $accessingIP === "127.0.0.1") {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }
                        return true;
                    }
                }
                
                return false;
                break;

            case 'defaultvalue':
            case 'uiconfig':
            case 'uiconfigdef':

                if (isset($form['include_defaults'])) { //this is a request to read all configs
                    return false;
                }
                if ($this->name == "Settings") {
                    if ((isset($form['action']) && ($form['action'] == 'update' || $form['action'] == 'read')) && $_SERVER["REMOTE_ADDR"] === "127.0.0.1") {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }
                        return true;
                    }
                }
                
                return false;
                break;

            case 'image':
                if ($this->name == "Settings" || $this->name == "Images") {
                    if ($aco['action'] == 'read' || $aco['action'] == 'count' || $aco['action'] == 'list') {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.".__IsModelUnscoped) action='".$aco['action']."' return TRUE");
                        }

                        return true;
                    } else {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.".__IsModelUnscoped) action='".$aco['action']."' return FALSE");
                        }

                        return false;
                    }
                }
                break;

            case 'meeting':
                if ($this->name == "Meetings" || $this->name == "Meeting"  ) {
                    if ($aco['action'] == 'register') {
                        return true;
                    }
                    if ($aco['action'] == 'read' && isset($form['registration_id'])) {
                        return true;
                    }
                }
                break;

            case 'trace':
                if ($this->name == "Traces" || $this->name == "Trace"  ) {
                    if(($aco['action'] == 'read' || $aco['action'] == 'export') && isset($form['k'])) {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped aco ) '.print_r($aco, true));
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped form) '.print_r($form, true));
                        return true;
                    }

                    if (isset($aco['action']) && ($aco['action'] == 'read' || $aco['action'] == 'export' || $aco['action'] == 'share')) {
                        // if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")
                        // return true;
                    }
                }


                return false;
                break;

            case 'reseller':
                if ($form['action'] == 'insight') {
                    if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")
                        return true;
                    return true;
                }
                break;

            case 'recording':
                if ($this->name == "Recordings" || $this->name == "Recording"  ) {
                    if ($form['action'] == 'play') {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }

                        return true;
                    }
                    if (strpos($form['action'], 'remote_') !== false ) {
                        if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1")
                        return true;
                    }
                }
                break;

            case 'vmailnag':
                if ($this->name == "Subscribers"   ) {
                    if ($form['action'] == 'update') {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }

                        return true;
                    }
                }
                break;

            case 'timeframe':
                if (($form['action'] == 'read' || $form['action'] == 'update' || $form['action'] == 'delete') && isset($form['isNode'])) {
                    if ($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1") {
                        if (Configure::read('NsTraceScope')) {
                            $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                        }
                        return true;
                    }
                }
                break;
            case 'timerange':
                if (($_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER["REMOTE_ADDR"] == "127.0.0.1") && isset($form['isNode'])) {
                    if (Configure::read('NsTraceScope')) {
                        $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return TRUE');
                    }
                    return true;
                }
                break;

            default:
                if (Configure::read('NsTraceScope')) {
                    $this->nslog('debug', '('.$this->name.'.__IsModelUnscoped) return FALSE');
                }

                return false;
                break;
        }
    }

    private function __getAcoAction(&$aco, $form)
    {
        if (isset($form['action'])) {
            switch (strtolower($form['action'])) {
                case 'postlog':
                case 'create':
                    $aco['action'] = 'create';
                    break;

                case 'list':
                case 'count':
                    $aco['action'] = 'list';
                    break;

                case 'report':
                case 'readevents':
                case 'emailhosts':
                case 'recordingcomplete':
                case 'gettranscriptionjob': //action has uppercases normally.
                case 'gettranscriptionsummary': //action has uppercases normally.
                case 'gettranscriptiontopics': //action has uppercases normally.
                case 'gettranscriptions': //action has uppercases normally.
                case 'read':
                    $aco['action'] = 'read';
                    break;

                case 'update':
                    $aco['action'] = 'update';
                    break;

                case 'reorder':
                    $aco['action'] = 'update';
                    break;

                case 'deleteevents':
                case 'delete':
                    $aco['action'] = 'delete';
                    break;

                case 'unmute':
                case 'mute':
                    $aco['action'] = 'update';
                    break;

                default:
                    // default to the "create" which require the highest authorization
                    $aco['action'] = 'create';
                    break;
            }

            return true;
        } else {
            if (Configure::read('NsTraceScope')) {
                $this->nslog('debug', '('.$this->name.'.checkScope.__getAcoAction) missing action and return FALSE');
            }

            return false;
        }
    }

    private function __getAcoOwnerInfo(&$aco, &$form)
    {
        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.__getAcoOwnerInfo) form '.print_r($form, true));
        }

        if (isset($form['owner_domain']) && isset($form['owner'])) {
            $aco['uid'] = $form['owner'].'@'.$form['owner_domain'];
            $aco['domain'] = $form['owner_domain'];
            $aco['user'] = $form['owner'];
        }//if (isset($form['owner_domain']) && isset($form['owner']))
        elseif (isset($form['domain']) && isset($form['user'])) {
            $aco['uid'] = $form['user'].'@'.$form['domain'];
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['user'];
        }//if (isset($form['domain']) && isset($form['user']))
        elseif (isset($form['domain']) && isset($form['dest'])) {
            $aco['uid'] = $form['dest'].'@'.$form['domain'];
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['dest'];
        } elseif (isset($form['owner_domain']) && isset($form['owner_uid'])) {
            $aco['uid'] = $form['owner_uid'].'@'.$form['owner_domain'];
            $aco['domain'] = $form['owner_domain'];
            $aco['user'] = $form['owner_uid'];
        }//if (isset($form['domain']) && isset($form['user']))
        elseif (isset($form['domain']) && isset($form['queue_name'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['queue_name'];
            $aco['uid'] = $form['queue_name'].'@'.$form['domain'];
        }//if (isset($form['domain']) && isset($form['queue_name']))
        elseif (isset($form['aor'])) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['aor'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [1] for territory $uid1 not found");
                if (isset($aco['model']) && $aco['model'] == 'connection') {
                    $this->loadModel('Connection');
                    $aco['uid'] = $this->Connection->getOwnerFromAor($form['aor']);
                } else {
                    $this->loadModel('Device');
                    $aco['uid'] = $this->Device->getOwnerFromAor($form['aor']);
                }
                Cache::write('oauth_aor_uid'.'_'.$form['aor'], $aco['uid'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                if (strpos($form['aor'], '@') !== false)
                  list($foobar, $aco['domain']) = explode('@', $form['aor']);
                elseif (isset($form['domain']))
                  $aco['domain'] = $form['domain'];
                elseif (isset($form['owner_domain']))
                  $aco['domain'] = $form['owner_domain'];
            }
            if (isset($aco['model']) && $aco['model'] == 'conference' && $aco['domain'] =="conference-bridge" && isset($form['domain'])) {
              $aco['domain'] = $form['domain'];
            }
        }//if (isset($form['aor']))
        elseif ($form['object'] =="agent" &&  isset($form['device']) && strpos($form['device'],"sip:") === FALSE) {
            $aco['uid'] = $form['device'];
            list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
        }
        elseif ($form['object'] =="agent" &&  isset($form['agent']) && strpos($form['agent'],"sip:") === FALSE) {
            $aco['uid'] = $form['agent'];
            list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
        }
        elseif (isset($form['device'])) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['device'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [2] for territory $uid1 not found");
                $this->loadModel('Device');
                $aco['uid'] = $this->Device->getOwnerFromAor($form['device']);

                Cache::write('oauth_aor_uid'.'_'.$form['device'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                if (strpos($form['device'], '@') !== false) {
                    list($foobar, $aco['domain']) = explode('@', $form['device']);
                }

            }
        }//if (isset($form['device']))
        elseif (isset($form['device1'])) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['device1'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [2] for territory $uid1 not found");
                $this->loadModel('Device');
                $aco['uid'] = $this->Device->getOwnerFromAor($form['device1']);

                Cache::write('oauth_aor_uid'.'_'.$form['device1'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                list($foobar, $aco['domain']) = explode('@', $form['device1']);
            }
        }//if (isset($form['device1']))
        elseif (isset($form['mac']) && $form['mac']!="" ) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['mac'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [3] for territory $uid1 not found");
                $this->loadModel('Mac');
                $aco['uid'] = $this->Mac->getOwnerFromMac($form['mac']);

                Cache::write('oauth_aor_uid'.'_'.$form['mac'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                if (isset($form['domain']))
                  $aco['domain'] = $form['domain'];
                //list($foobar, $aco['domain']) = explode('@', $form['device']);
                //???
            }
        }//if (isset($form['device']))
        elseif (isset($form['conference'])) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['conference'], '_oauth_token_');
            if (isset($uid1) && $uid1 != null) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [4] for territory $uid1 not found");
                $this->loadModel('Device');
                $aco['uid'] = $this->Device->getOwnerFromAor($form['conference']);
                Cache::write('oauth_aor_uid'.'_'.$form['conference'], $aco['uid'], '_oauth_token_');
            }
            list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
        }
        elseif (isset($form['service_id'])) {

            $this->loadModel('RemoteArchive');
            $this->RemoteArchive->getOwnerFromServiceId($aco,$form['service_id']);

            if (isset($aco['uid']))
              list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            else {
              $aco['domain'] = '*';
              $aco['user'] = '*';
              $aco['uid'] = '*';
            }
        }
        elseif (isset($form['owner_domain'])) {
            $aco['domain'] = $form['owner_domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['owner_domain']))
        elseif (isset($form['huntgroup_domain'])) {
            $aco['domain'] = $form['huntgroup_domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }
        elseif (isset($form['domain'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['domain']))
        else {
            $aco['domain'] = '*';
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }

        // print_r($form);
        // print_r($aco);
    }

    private function __getNcsAcoOwnerInfo(&$aco, &$form)
    {

        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.__getAcoOwnerInfo) form '.print_r($form, true));
        }

        if (isset($form['domain'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }
        if (isset($form['conference_match'])) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['conference_match'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [5] for territory $uid1 not found");
                if (isset($aco['model']) && ($aco['model'] == 'participant' || $aco['model'] == 'conference') ) {
                    $this->loadModel('Conference');
                    $aco['uid'] = $this->Conference->getOwnerFromAor($form['conference_match']);
                }
                else {
                    $this->loadModel('Device');
                    $aco['uid'] = $this->Device->getOwnerFromAor($form['conference_match']);
                }
                Cache::write('oauth_aor_uid'.'_'.$form['conference_match'], '_oauth_token_');
            }

            if (isset($aco['uid'])) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            }
        }//if (isset($form['device']))
        elseif (isset($form['id'])) {
            if (isset($aco['model']) && ($aco['model'] == 'meeting' || $aco['model'] == 'attendee') ) {
                $this->loadModel('Meeting');
                $aco['uid'] = $this->Meeting->getOwnerFromId($form['id']);

                if (isset($aco['uid'])) {
                    list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
                }
                else if (isset($form['user']) && $form['user'] == 'guest')
                {
                    // aco is owner
                }
                else if (isset($form['user']) && isset($form['domain']))
                {
                    $aco['domain'] = $form['domain'];
                  $aco['user'] = $form['user'];
                  $aco['uid'] = $form['user']."@".$form['domain'];
                }
            }
        }
        elseif (isset($form['domain']) && isset($form['user'])) {
            if ($this->startsWith($form['user'], "guest_")) {
                $form['user'] = "guest";
            }

            $aco['uid'] = $form['user'].'@'.$form['domain'];
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['user'];
        }
        elseif (isset($form['domain'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['domain']))

        if (isset($form['participant']) && isset($form['uid']))
        {
          list($devicePart, $tmp) = explode('@', str_replace("sip:","",$form['participant']));
          list($userPart, $tmp) = explode('@', $form['uid']);
          if (strpos($devicePart, $userPart) !== false)
          {
            $aco['user'] = $userPart;
            $aco['uid'] = $form['uid'];
          }
        }

        $this->loadModel('Attendee');

        $allowPresenterHostCheck = isset($form['object']) && ($form['object'] == 'participant' || $form['object'] == 'meeting');
        $meeting_id = null;
        if ($meeting_id == null && isset($form['meeting_id'])) {
            $meeting_id = $form['meeting_id'];
        }
        if ($meeting_id == null && isset($form['id'])) {
            $meeting_id = $form['id'];
        }
        if ($meeting_id == null && isset($form['conference_match'])) {
            $meeting_id = str_replace("sip:","",str_replace(".video.bridge","",explode("@",$form['conference_match'])[0]));
        }

        $attendeeId = isset($form['attendee_id']) ? $form['attendee_id'] : null;
        if (isset($form['user']) && strpos($form['user'], "guest_") !== false) {
            $attendeeId = str_replace("guest_", "", $form['user']);
        }

        if ($allowPresenterHostCheck && $form['action'] == 'update' && $attendeeId != null && !isset($form['user']))
        {
            if ($this->Attendee->checkHostPresenter(null, $meeting_id, $attendeeId))
            {
                if (isset($form['uid']) && strpos($form['uid'], $attendeeId) !== false)
                {
                    $uid = str_replace("_".$attendeeId, "", $form['uid']);
                    $tmpArr = explode("@", $uid);

                    $aco['user'] = $tmpArr[0];
                    $aco['uid'] = $uid;
                }
                else if (isset($form['user']) && isset($form['domain']))
                {
                    $uid = str_replace("_".$attendeeId, "", $form['user'])."@".$form['domain'];
                    $tmpArr = explode("@", $uid);

                    $aco['user'] = $form['user'];
                    $aco['uid'] = $uid;
                }
                else if (isset($form['domain']))
                {
                    $uid = "guest"."@".$form['domain'];
                    $tmpArr = explode("@", $uid);

                    $aco['user'] = $tmpArr[0];
                    $aco['uid'] = $uid;
                }
            }
        }
        else if ($allowPresenterHostCheck && $form['action'] == 'update' && isset($form['user']))
        {
            $uid = $form['user']."@".$form['domain'];

            if ($this->Attendee->checkHostPresenter($uid,$meeting_id, $attendeeId))
            {
              if (isset($form['uid']) && $attendeeId != null && strpos($form['uid'], $attendeeId) !== false)
              {
                  $uid = str_replace("_".$attendeeId, "", $form['uid']);
                  $tmpArr = explode("@", $uid);

                  $aco['user'] = $tmpArr[0];
                  $aco['uid'] = $uid;
              }
              else if (isset($form['user']) && isset($form['domain']))
              {
                  $uid = str_replace("_".$attendeeId, "", $form['user'])."@".$form['domain'];
                  $tmpArr = explode("@", $uid);

                  $aco['user'] = $form['user'];
                  $aco['uid'] = $uid;
              }
              else if (isset($form['domain']))
              {
                  $uid = "guest"."@".$form['domain'];
                  $tmpArr = explode("@", $uid);

                  $aco['user'] = $tmpArr[0];
                  $aco['uid'] = $uid;
              }
            }
        }

    }

    private function __getAcoDestinationInfo(&$aco, &$form)
    {
        if (isset($form['dest_domain']) && isset($form['to_user']) && $form['dest_domain'] == 'conference-brdge') {
            //conference bridge in DID table use case.
            if (!isset($this->Device)) {
                $this->loadModel('Device');
            }
            $aco['uid'] = $this->Device->getOwnerFromAor('sip:'.$form['to_user'].'@'.$form['dest_domain']);
            list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
        } elseif (isset($form['device1']) && $form['domain']) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['device1'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [2] for territory $uid1 not found");
                $this->loadModel('Device');
                $aco['uid'] = $this->Device->getOwnerFromAor($form['device1']);
                Cache::write('oauth_aor_uid'.'_'.$form['device1'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                list($foobar, $aco['domain']) = explode('@', $form['device1']);
            }
            if ($form['domain']!=$aco['domain'])
            {
              $aco['domain']=$form['domain'];
              $aco['user']="*";
            }
        }//if (isset($form['device1']))
        elseif (isset($form['dest_domain']) && isset($form['dest_user']) && $form['dest_user'] != '[*]') {
            $aco['uid'] = $form['dest_user'].'@'.$form['dest_domain'];
            $aco['domain'] = $form['dest_domain'];
            $aco['user'] = $form['dest_user'];
        }//if (isset($form['dest_domain']) && isset($form['dest_user']))
        elseif (isset($form['domain']) && isset($form['dest'])) {
            $aco['uid'] = $form['dest'].'@'.$form['domain'];
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['dest'];
        } elseif (isset($form['domain'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['to_host']))
        elseif (isset($form['dest_domain'])) {
            $aco['domain'] = $form['dest_domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['dest_domain']))
        elseif (isset($form['to_host'])) {
            $aco['domain'] = $form['to_host'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['to_host']))
        //backdoor way to passw authentication
        else {
            $aco['domain'] = '*';
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }
    }

    private function __getAcoUserInfo(&$aco, &$form)
    {
        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.__getAcoUserInfo) form '.print_r($form, true));
        }
        if (isset($form['uid'])) {
            if (!$form['uid']) {
                $this->nslog('debug', '('.$this->name.'.__getAcoUserInfo) uid provided was not valid ');
                die;
            }

            $aco['uid'] = $form['uid'];
            if (strpos($form['uid'], '@')) {
                list($aco['user'], $aco['domain']) = explode('@', $form['uid']);
            } elseif (isset($form['domain'])) {
                $aco['domain'] = $form['domain'];
                $aco['user'] = '*';
            } else {
                $aco['domain'] = '*';
                $aco['user'] = '*';
            }
        }//if (isset($form['uid']))
        elseif (isset($form['domain']) && isset($form['user'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['user'];
            $aco['uid'] = $form['user'].'@'.$form['domain'];
        }//if (isset($form['domain']) && isset($form['user']))
        elseif (isset($form['domain']) && isset($form['owner'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['owner'];
            $aco['uid'] = $form['owner'].'@'.$form['domain'];
        }//if (isset($form['domain']) && isset($form['user']))
        elseif (isset($form['domain']) && isset($form['queue_name'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['queue_name'];
            $aco['uid'] = $form['queue_name'].'@'.$form['domain'];
        }//elseif (isset($form['domain']) && isset($form['queue_name']))
        elseif (isset($form['domain']) && isset($form['agent'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['agent'];
            $aco['uid'] = $form['agent'].'@'.$form['domain'];
        }//elseif (isset($form['domain']) && isset($form['agent']))
        elseif (isset($form['login'])) {
            if (!$form['login']) {
                $this->nslog('debug', '('.$this->name.'.__getAcoUserInfo) login provided was not valid ');
                die;
            }
            $this->loadModel('Subscriber');
            $aco['uid'] = $this->Subscriber->getUidFromLogin($form['login']);
            list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
        }//if (isset($form['login']))
        elseif (isset($form['device1']) && $form['domain']) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['device1'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [2] for territory $uid1 not found");
                $this->loadModel('Device');
                $aco['uid'] = $this->Device->getOwnerFromAor($form['device1']);
                Cache::write('oauth_aor_uid'.'_'.$form['device1'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                list($foobar, $aco['domain']) = explode('@', $form['device1']);
            }
            if ($form['domain']!=$aco['domain'])
            {
              $aco['domain'] = "*";
              $aco['user'] = "*";
            }
        }
        elseif (isset($form['device']) && isset($form['domain'])) {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['device'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [2] for territory $uid1 not found");
                $this->loadModel('Device');
                $aco['uid'] = $this->Device->getOwnerFromAor($form['device']);

                Cache::write('oauth_aor_uid'.'_'.$form['device'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                if (strpos($form['device'], '@') !== false) {
                    list($foobar, $aco['domain']) = explode('@', $form['device']);
                }
                elseif (isset($form['domain']))
                    $aco['domain'] = $form['domain'];

            }
            if ($form['domain']!=$aco['domain'])
            {
              $aco['domain']=$form['domain'];
              $aco['user']="*";
            }
        }//if (isset($form['device']))
        elseif (isset($form['mac']) && $form['mac']!= "") {
            $uid1 = Cache::read('oauth_aor_uid'.'_'.$form['mac'], '_oauth_token_');
            if (isset($uid1) && $uid1 !== false) {
                $aco['uid'] = $uid1;
            } else {
                $this->nslog('debug', "Cache record [3] for uid $uid1 not found");
                $this->loadModel('Mac');
                $aco['uid'] = $this->Mac->getOwnerFromMac($form['mac']);
                Cache::write('oauth_aor_uid'.'_'.$form['mac'], '_oauth_token_');
            }

            if (strpos($aco['uid'], '@') !== false) {
                list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            } else {
                $aco['user'] = '*';
                if (isset($form['domain']))
                  $aco['domain'] = $form['domain'];
                //list($foobar, $aco['domain']) = explode('@', $form['device']);
                //???
            }
        }
        elseif (isset($form['domain'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['domain']))
        elseif (isset($form['huntgroup_domain'])) {
            $aco['domain'] = $form['huntgroup_domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['domain']))
        else {
            $aco['domain'] = '*';
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }
        $form['domain'] = $aco['domain'];
        if ($aco['user'] != '*') {
            $form['user'] = $aco['user'];
        } else {
            unset($form['user']);
        }
        if ($aco['uid'] != '*') {
            $form['uid'] = $aco['uid'];
        } else {
            unset($form['uid']);
        }

        // print_r($form);
        // print_r($aco);
    }

    private function __getAcoStatInfo(&$aco, &$form)
    {
        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.__getAcoStatInfo) form '.print_r($form, true));
        }
        if (isset($form['domain']) && isset($form['op_term_sub'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = $form['op_term_sub'];
            $aco['uid'] = $form['op_term_sub'].'@'.$form['domain'];
        }//if (isset($form['domain']) && isset($form['user']))
        elseif (isset($form['domain'])) {
            $aco['domain'] = $form['domain'];
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }//if (isset($form['domain']))
        else {
            $aco['domain'] = '*';
            $aco['user'] = '*';
            $aco['uid'] = '*';
        }
        $form['domain'] = $aco['domain'];
        if ($aco['user'] != '*') {
            $form['user'] = $aco['user'];
        } else {
            unset($form['user']);
        }
        if ($aco['uid'] != '*') {
            $form['uid'] = $aco['uid'];
        } else {
            unset($form['uid']);
        }
    }

    private function __getAcoRecordingStorageInfo(&$aco, &$form)
    {
        $aco['domain'] = '*';
        $aco['user'] = '*';
        $aco['uid'] = '*';
        if (isset($form['service_id']) && $form['service_id'] != "testonly") {

            $this->loadModel('RemoteArchive');
            $this->RemoteArchive->getOwnerFromServiceId($aco,$form['service_id']);

            if (isset($aco['uid']) && $aco['uid'] != "*")
              list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
            else {
              $aco['domain'] = '*';
              $aco['user'] = '*';
              $aco['uid'] = '*';
            }
        }

        if (isset($form['service_type']) && $form['service_type'] == 'domain') {
            $aco['domain'] = $form['service_value'];
        }
        else if (isset($form['domain']) ) {
            $aco['domain'] = $form['domain'];
        }
    }

    private function __getAcoTerritoryDomaiUid(&$aco, &$form) {
        if (isset($form['domain']) && $form['domain'] == '') {
            if (isset($this->token['scope']) && $this->token['scope'] == 'Super User') {
            } else {
                return false;
            }
        }
        switch ($form['object']) {
            case 'agent':
            case 'audio':
            case 'conferencecdr':
            case 'conference':
            case 'recording':
            case 'recordingstorage':
            case 'timerange':
            case 'timeframe':
                if ($form['object'] == "recordingstorage" || ($form['object'] =="recording" && $form['action'] == 'remote_validate'))
                {
                  $this->__getAcoRecordingStorageInfo($aco, $form);
                  break;
                }
                $this->__getAcoOwnerInfo($aco, $form);
                break;

            case 'connection':
            case 'device':
            case 'mac':
            case 'phoneconfiguration':
                if ($form['action'] == 'create' || $form['action'] == 'update') {
                    $this->__getAcoUserInfo($aco, $form);
                } else {
                    $this->__getAcoOwnerInfo($aco, $form);
                }
                break;
            case 'meeting':
            case 'attendee':
            case 'participant':
                $this->__getNcsAcoOwnerInfo($aco, $form);
                break;
            case 'phonenumber':
                $this->__getAcoDestinationInfo($aco, $form);
                break;
            case 'callqueuereport':
                $this->__getAcoStatInfo($aco, $form);
                break;

            case 'apikey':
                $this->loadModel('Apikey');

                $this->loadModel('OauthJwt');
                if (isset($form['key_id']))
                {
                    if (isset($form['key_id']) && $form['key_id'] == "~" && isset($this->token['key_id']))
                    $form['key_id'] =$this->OauthJwt->getHeaderToken();;
                    $tmptoken=array();
                    $this->Apikey->verifyApiKey($form['key_id'],$tmptoken,$form['object'],$form['action']);

                    if (empty($tmptoken))
                        $this->__getAcoUserInfo($aco, $form);
                    else{
                        if (isset($tmptoken['uid']) && $tmptoken['uid'] != null)
                        {
                            $aco['uid'] = $tmptoken['uid'];
                            list($aco['user'], $aco['domain']) = explode('@', $aco['uid']);
                        }
                        if (isset($tmptoken['domain'])) $aco['domain'] = $tmptoken['domain'];
                        if (isset($tmptoken['user'])) $aco['user'] = $tmptoken['user'];
                        if (isset($tmptoken['reseller'])) $aco['reseller'] = $tmptoken['reseller'];
                    }


                }
                else
                    $this->__getAcoUserInfo($aco, $form);
                break;

            case 'push':
                if ($form['action'] == 'postLog') {
                    $this->__getAcoOwnerInfo($aco, $form);
                } else {
                    $this->__getAcoUserInfo($aco, $form);
                }
                break;

            // allow for the read of the video plans for a new domain/reseller
            case 'video':
                if ($form['action'] == 'read' && isset($form['slug_id']) && isset($form['is_new']) && $form['domain'] == 'fakerandomdomain123456789' && ($this->token['scope'] == 'Super User' || $this->token['scope'] == 'Reseller')) {
                    return true;
                }
            default:
                $this->__getAcoUserInfo($aco, $form);
                break;
        }

        if (isset($aco['territory']) && $aco['territory'] != "*" && ($aco['model'] == 'recording' || $aco['model'] == 'recordingstorage'))
        {
          //remote storage allowed.
        }
        else if (isset($aco['domain']) && $aco['domain'] != null && $aco['domain'] != '*') {
            $ter = Cache::read('oauth_domains_reseller'.'_'.$aco['domain'], '_oauth_token_');
            if (isset($ter) && $ter !== false && $ter != '*') {
                $aco['territory'] = $ter;
            } else {
                $this->nslog('debug', "Cache record [6] for territory ".$aco['domain']." not found");
                if ($aco['action'] == 'create' && $aco['model'] == 'domain') {
                    $aco['territory'] = $form['territory'];
                } elseif ($aco['domain'] != '*') {
                    $this->loadModel('Domain');
                    $aco['territory'] = $this->Domain->getTerritory($aco['domain']);
                    if (isset($aco['territory']) && $aco['territory'] != '') {
                        Cache::write('oauth_domains_reseller'.'_'.$aco['domain'], $aco['territory'], '_oauth_token_');
                    }
                }
            }
        } elseif (isset($form['territory']) && $form['territory'] != '*') {
            $aco['territory'] = $form['territory'];
        } else {
            $aco['territory'] = '*';
        }



        if (isset($aco['domain']) && $aco['domain'] != null && $aco['domain'] != '*' &&  isset($aco['user']) && $aco['user'] != null && $aco['user'] != '*') {
            $key = 'oauth_domains_site'.'_'.$aco['user']."_".$aco['domain'];
            $site = Cache::read($key, '_oauth_token_');
            if (isset($site) && $site !== false && $site != '*') {
                $aco['group'] = $site;
            } else {
                if ($aco['action'] == 'create' && $aco['model'] == 'subscriber' && isset($form['site'])) {
                    $aco['group'] = $form['site'];
                }
                else if ($aco['model'] == 'agent' && isset($form['entry_option']) && $form['entry_option'] == 'offnet' && isset($form['site'])) {
                    $aco['group'] = $form['site'];
                }
                else {
                    $this->loadModel('Subscriber');
                    $aco['group'] = $this->Subscriber->getSite($aco['user'],$aco['domain']);
                    if (isset($aco['group']) && $aco['group'] != '') {
                        Cache::write($key,$aco['group'], '_oauth_token_');
                    }
                    else {
                        Cache::write($key, "*", '_oauth_token_');
                    }
                }
            }
        }
        elseif (isset($form['site']) && $form['site'] != '*') {
            $aco['group'] = $form['site'];
        } elseif (isset($form['group']) && $form['group'] != '*') {
            $aco['group'] = $form['group'];
        } else {
            $aco['group'] = "*";
        }


        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.__getAcoTerritoryDomaiUid) aco '.print_r($aco, true));
        }

        return true;
    }

    // compares ['Subscriber']['scope'] to $ownerScope and returns the subject's scope or
    // FALSE if the tiered access is invalid. horizontal access will return FALSE
    public function checkAccessTier($maskScope, $ownerScope)
    {
        $validScope = $maskScope;

        switch ($maskScope) { // target user scope
            case 'Super User':
                $validScope = false;
                break;
            case 'Reseller':
                if ($ownerScope != 'Super User') {
                    $validScope = false;
                }
                break;
            case 'Office Manager':
                if ($ownerScope != 'Super User' && $ownerScope != 'Reseller') {
                    $validScope = false;
                }
                break;
            case 'Call Center Supervisor':
                if ($ownerScope != 'Super User' && $ownerScope != 'Reseller' && $ownerScope != 'Office Manager') {
                    $validScope = false;
                }
                break;

            case 'Site Manager':
                if ($ownerScope != 'Super User' && $ownerScope != 'Reseller' && $ownerScope != 'Office Manager') {
                    $validScope = false;
                }
                break;
        }

        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.checkAccessTier) '.'ms owner:[' . $ownerScope . '] target:[' . $maskScope . '] return: '.$validScope);
        }

        return $validScope;
    }

    // Compares users scope with new/updated users requested scope
    // FALSE if the scope would be in vialation
    public function checkScopeRights($newScope, $ownerScope)
    {
        $validScope = true;

        if ($ownerScope == "resetOnly") return $validScope;

        switch ($newScope) { // target user scope
            case 'NDP':
            case 'Super User Read Only':
            case 'Super User':
                if ($ownerScope != 'Super User') {
                    $validScope = false;
                }
                break;
            case 'Reseller':
                if ($ownerScope != 'Super User' && $ownerScope != 'Reseller') {
                    $validScope = false;
                }
                break;
            case 'Office Manager':
                if ($ownerScope != 'Super User' && $ownerScope != 'Reseller' && $ownerScope != 'Office Manager') {
                    $validScope = false;
                }
                break;
            case 'Call Center Supervisor':
                if ($ownerScope != 'Super User' && $ownerScope != 'Reseller' && $ownerScope != 'Office Manager'  && $ownerScope != 'Call Center Supervisor') {
                    $validScope = false;
                }
                break;
            case 'Site Manager':
                if ($ownerScope != 'Super User' && $ownerScope != 'Reseller' && $ownerScope != 'Office Manager'  && $ownerScope != 'Site Manager') {
                    $validScope = false;
                }
                break;
        }

        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.checkScopeRights) '.'ms owner:[' . $ownerScope . '] target:[' . $newScope . '] return: '.$validScope);
        }

        return $validScope;
    }

    public function checkScopeExists($scope)
    {
        switch ($scope) { // target user scope
            case 'NDP':
            case 'Super User Read Only':
            case 'Super User':
            case 'Reseller':
            case 'Office Manager':
            case 'Call Center Supervisor':
            case 'Call Center Agent':
            case 'Site Manager':
            case 'Basic User':
            case 'Advanced User':
            case 'No Portal':
                break;
            default:
                $this->loadModel('Scopepriv');
                if (!$this->Scopepriv->isScope($scope)) {
                    return false;
                }
            break;
        }

        return true;
    }

    public function checkScope($token, &$form, &$aco)
    {
        return $this->__checkScope($token, $form, $aco);
    }

    private function __checkScope($token, &$form, &$aco)
    {

        $start = microtime(true);
        $strTrace = '';
        //--------------------------------------------------------------------------------------------------------------------------------
        // Parse and Scrub Action

        if (!$this->__getAcoAction($aco, $form)) {
            $this->NsLogError('checkScope', $strTrace.' fail to parse Action', print_r($form, true));

            return false;
        }
        $strTrace .= ' aco.action'."='".$aco['action']."'";

        if ($this->isV2() && isset($token) && isset($token['readonly']) && $token['readonly'] =='yes')
        {
            if ($aco['action'] != 'read' && $aco['action'] != 'list' && $aco['action'] != 'count') 
                $this->errorResponse(403, "Apikey being used is for read only actions");
        }

        //--------------------------------------------------------------------------------------------------------------------------------
        // Parse and Scrub Model

        if (!$this->__getAcoModel($aco, $form)) {
            $this->NsLogError('checkScope', $strTrace.' fail to parse Model', print_r($form, true));

            return false;
        }
        $strTrace .= ' aco.model'."='".$aco['model']."'";

        //--------------------------------------------------------------------------------------------------------------------------------
        // Parse and Scrub Teritory, Domain, Uid

        if (!$this->__getAcoTerritoryDomaiUid($aco, $form)) {
            $this->NsLogError('checkScope', $strTrace.' fail to parse Territory, Domain and Uid', print_r($form, true));

            return false;
        }
        if (isset($aco['uid'])) {
            $strTrace .= ' aco.uid'."='".$aco['uid']."'";
        }
        if (isset($aco['domain'])) {
            $strTrace .= ' aco.domain'."='".$aco['domain']."'";
        }
        if (isset($aco['territory'])) {
            $strTrace .= ' aco.territory'."='".$aco['territory']."'";
        }
        if (isset($aco['group'])) {
            $strTrace .= ' aco.group'."='".$aco['group']."'";
        }

        $this->sendOriginHeaders();

        //--------------------------------------------------------------------------------------------------------------------------------
        // Check Priviledge for Model vs Territory/Domain/Uid vs Action
        if (!Configure::read('NsLocalRequireOauth') || !Configure::read('NsCheckScope')) {
            return true;
        } elseif ($this->__IsModelUnscoped($aco, $form)) {
            return true;
        }

        if (Configure::read('NsAllowIPWithoutAuth') != '') {
            if (!isset($this->Oauthtoken)) {
                $this->loadModel('Oauthtoken');
            }
            if ($this->Oauthtoken->isStaticAllowedIP()) {
                return true;
            }
        }


        $this->loadModel('Scopepriv');

        if (!isset($token['group']))
        {
            if (isset($token['uid']) && $token['uid']!="*")
            {
                //THIS CODE SHOULD BE TEMPORARY
                //$this->nslog('error', '('.$this->name.".check) temp lookup of site");
                list ($u,$d) = explode("@",$token['uid'] );

                $key = 'oauth_domains_site'.'_'.$u."_".$d;
                $site = Cache::read($key, '_oauth_token_');
                if (isset($site) && $site !== false && $site != '*') {
                    $token['group'] = $site;
                } else {
                    $this->nslog('debug', "Cache record [9] for " .$u."@".$d." not found");
                    $this->loadModel('Subscriber');
                    $token['group'] = $this->Subscriber->getSite($u,$d);
                    if (isset($token['group']) && $token['group'] != '') {
                        Cache::write($key,$token['group'], '_oauth_token_');
                    }
                    else {
                        Cache::write($key, "*", '_oauth_token_');
                    }
                }
            }
        }
        if (!$this->Scopepriv->check($token, $aco, $strTrace)) {
            if (isset($aco['action']) && ($aco['action'] == 'count' || $aco['action'] == 'list')) {
                if ($form['action'] == 'count') {
                    $results['xml']['total'] = '0';
                } else { //if ($form['action']=="list")
                    $results['xml'] = array();
                }

                if ($aco['model'] != "connection") { //We need it to count other sip trunks to avoid overlap.
                    if ($form['format'] == 'json') {
                        $this->responseWithJson(array());
                    } elseif ($form['format'] == 'Rfc1738') {
                        $this->responseWithRfc1738(array());
                    } else {
                        $this->responseWithXml($results);
                    }
                    exit;
                }
            }

            if (isset($aco['model']) && ($aco['model'] == 'mac') && isset($aco['action']) && ($aco['action'] == 'update')) {
                if (isset($aco['domain']) && isset($form['domain']) && $form['domain'] == $aco['domain']) {
                    if (isset($form['device1']) && $form['device1'] == 'n/a') {
                        return true;
                    }
                }
            }

            if (isset($aco['model']) && ($aco['model'] == 'phoneconfiguration') && isset($aco['action']) && ($aco['action'] == 'read')) {
                if (isset($aco['domain']) && isset($form['domain']) && $form['domain'] == $aco['domain']) {
                    if (isset($form['mac']) && $form['mac'] == 'n/a') {
                        return true;
                    }
                }
            }

            if (isset($aco['model']) && ($aco['model'] == 'answerrule') && isset($aco['action']) && ($aco['action'] == 'read')) {
                if (isset($aco['domain']) && isset($form['domain']) && $form['domain'] == $aco['domain']) {
                    if (isset($form['user']) && ($form['user'] == 'domain' || $form['user'] == '*')) {
                        if ($token['scope'] == 'Site Manager') {
                            return true;
                        }
                    }
                }
            }

            if (Configure::read('NsTraceScope')) {
                $this->nslog('debug', '('.$this->name.'.Scopepriv.check) is FALSE form '.print_r($form, true).' aco'.print_r($aco, true));
            }

            $this->NsLogError('checkScope', $strTrace.' is FALSE', print_r($form, true));
            // print_r($this->name);
            // print_r($strTrace);
            // print_r($form);
            // print_r($aco);
            return false;
        }


        if ($aco['action'] == 'delete' && isset($form['domain']) && $form['domain'] == '') {
            return false;
        }


        if (isset($aco['territory']) && !isset($form['territory']) && (!isset($form['domain']) || $form['domain'] == '*' || $form['domain'] == 'default') && !isset($form['owner_domain'])) {
            if ($aco['territory'] != '*') {
                $form['territory'] = $aco['territory'];
                $this->nslog('debug', '('.$this->name.".checkScope) form['territory'] = aco['territory'] ".print_r($form, true));
            }
        }

        //--------------------------------------------------------------------------------------------------------------------------------
        $end = microtime(true);
        if (Configure::read('NsTraceScope')) {
            $this->nslog('debug', '('.$this->name.'.checkScope) time='.$this->_printMS($start, $end).'ms return TRUE');
        }

        $this->sendOriginHeaders();


        return true;
    }

    public function NsStringEscape($strInput)
    {
        $input = trim((string)$strInput);
        $db = ConnectionManager::getDataSource('default');
        $conn = $db->getConnection();
        if ($conn instanceof mysqli) {
            return $conn->real_escape_string($input);
        }
        if ($conn instanceof PDO) {
            $quoted = $conn->quote($input);
            if ($quoted === false) {
                throw new \RuntimeException('PDO::quote() failed');
            }
            return substr($quoted, 1, -1);
        }
        throw new \RuntimeException(
            'NsStringEscape: unsupported connection type ' . get_class($conn)
        );

    }

    public function startsWith($haystack, $needle)
    {
        return !strncmp($haystack, $needle, strlen($needle));
    }

    public function __GetAASeachConditions($conditions, $form)
    {
        $conditions['OR'][0] = array('Feature.parameters LIKE' => 'Prompt%');
        $conditions['OR'][1] = array('Feature.parameters LIKE' => 'Announce%');
        $conditions['OR'][2] = array('Feature.parameters' => 'aa');
        $conditions['OR'][3] = array('Feature.parameters' => 'AAMain');
        $conditions['OR'][4] = array('Feature.parameters' => 'AAAfter');
        $conditions['OR'][5] = array('Feature.parameters' => 'AAHoliday');

        if (isset($form['domain'])) {
            $domain = $form['domain'];
        }
        if (isset($form['owner_domain'])) {
            $domain = $form['owner_domain'];
        }
        if (isset($form['user'])) {
            $user = $form['user'];
        }
        if (isset($form['owner'])) {
            $user = $form['owner'];
        }

        $conditions['object'] = $form['object'];

        if (isset($user) && isset($domain)) {
            $conditions['callee_match'] = $user.'@'.$domain;
        } elseif (isset($domain)) {
            $conditions['callee_match LIKE'] = '%@'.$domain;
        }

        $conditions['control'] = 'e';
        $conditions['group'][0] = 'callee_match';
        $conditions['group'][1] = 'parameters';
        $conditions['fields'] = 'callee_match,parameters,time_frame';
        $conditions['order'] = 'callee_match';

        if (isset($form['filter_users'])) {
            if ($form['filter_users'] == 'no' || $form['filter_users'] == 'false') {
            } else {
                foreach (explode(',', $form['filter_users']) as $ignore) {
                    $conditions['NOT'][]['callee_match LIKE '] = $ignore.'@%';
                }
            }
        }

        $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $form)  '.print_r($form, true));

        // if (isset($form['site']) && !isset($conditions['callee_match'])) {
        //   $querySub['conditions']['site'] = $form['site'];
        //   $querySub['conditions']['aor_host'] = $domain;
        //   $querySub['fields'] = 'aor_user,aor_host,site';
        //   $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $querySub)  '.print_r($querySub, true));
        //
        //   $this->loadModel('Subscriber');
        //   $resultSub = $this->Subscriber->find('all', $querySub);
        //   $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $resultSub)  '.print_r($resultSub, true));
        //   foreach ($resultSub as $sub)
        //   {
        //     $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $sub)  '.print_r($sub, true));
        //     $conditions['OR'][] = $sub['Subscriber']['aor_user'].'@'.$sub['Subscriber']['aor_host'];
        //     unset($conditions['callee_match LIKE']);
        //   }
        //
        //
        // }

        $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $conditions)  '.print_r($conditions, true));

        if (isset($form['starting'])) {
            $conditions['parameters'] = $form['starting'];
        }

        return $conditions;
    }

    public function __GetAASeachConditionsXXX($conditions, $form)
    {
        $conditions["AND"][0]['OR'][0] = array('Feature.parameters LIKE' => 'Prompt%');
        $conditions["AND"][0]['OR'][1] = array('Feature.parameters LIKE' => 'Announce%');
        $conditions["AND"][0]['OR'][2] = array('Feature.parameters' => 'aa');
        $conditions["AND"][0]['OR'][3] = array('Feature.parameters' => 'AAMain');
        $conditions["AND"][0]['OR'][4] = array('Feature.parameters' => 'AAAfter');
        $conditions["AND"][0]['OR'][5] = array('Feature.parameters' => 'AAHoliday');

        if (isset($form['domain'])) {
            $domain = $form['domain'];
        }
        if (isset($form['owner_domain'])) {
            $domain = $form['owner_domain'];
        }
        if (isset($form['user'])) {
            $user = $form['user'];
        }
        if (isset($form['owner'])) {
            $user = $form['owner'];
        }

        $conditions['object'] = $form['object'];

        if (isset($user) && isset($domain)) {
            $conditions['callee_match'] = $user.'@'.$domain;
        } elseif (isset($domain)) {
            $conditions['callee_match LIKE'] = '%@'.$domain;
        }

        $conditions["AND"][1]['control'] = 'e';
        $conditions['group'][0] = 'callee_match';
        $conditions['group'][1] = 'parameters';
        $conditions['fields'] = 'callee_match,parameters,time_frame';
        $conditions['order'] = 'callee_match';

        if (isset($form['filter_users'])) {
            if ($form['filter_users'] == 'no' || $form['filter_users'] == 'false') {
            } else {
                foreach (explode(',', $form['filter_users']) as $ignore) {
                    $conditions["AND"][2]['NOT'][]['callee_match LIKE '] = $ignore.'@%';
                }
            }
        }

        $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $form)  '.print_r($form, true));

        // if (isset($form['site']) && !isset($conditions['callee_match'])) {
        //   $querySub['conditions']['site'] = $form['site'];
        //   $querySub['conditions']['aor_host'] = $domain;
        //   $querySub['fields'] = 'aor_user,aor_host,site';
        //   $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $querySub)  '.print_r($querySub, true));
        //
        //   $this->loadModel('Subscriber');
        //   $resultSub = $this->Subscriber->find('all', $querySub);
        //   $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $resultSub)  '.print_r($resultSub, true));
        //   foreach ($resultSub as $sub)
        //   {
        //     $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $sub)  '.print_r($sub, true));
        //     $conditions["AND"][3]['OR'][] = $sub['Subscriber']['aor_user'].'@'.$sub['Subscriber']['aor_host'];
        //     unset($conditions['callee_match LIKE']);
        //   }
        //
        //
        // }

        $this->nslog('debug', '('.$this->name.'.__GetAASeachConditions $conditions)  '.print_r($conditions, true));

        if (isset($form['starting'])) {
            $conditions['parameters'] = $form['starting'];
        }

        return $conditions;
    }

    public function __GetDialRulesSeachConditions($conditions, $form)
    {
        if (isset($form['domain'])) {
            $domain = $form['domain'];
        }
        if (isset($form['owner_domain'])) {
            $domain = $form['owner_domain'];
        }
        if (isset($form['owner_domain'])) {
            $domain = $form['owner_domain'];
        }



        if ($form['object'] == 'phonenumber' && isset($domain) && !isset($form['dest_domain'])) {
            $form['dest_domain'] = $domain;
        }

        if (isset($form['dialplan'])) {
            $dialplan = $form['dialplan'];
        }
        if (isset($form['plan'])) {
            $dialplan = $form['plan'];
        }

        if (isset($form['matchrule'])) {
            $matchrule = $form['matchrule'];
        } elseif (isset($form['matchrule_LIKE'])) {
            $conditions['matchrule LIKE'] = $form['matchrule_LIKE'];
        }

        if (isset($form['match_from'])) {
            $conditions['match_from'] = $form['match_from'];
        }

        if (isset($form['parameter'])) {
            $conditions['parameter'] = $form['parameter'];
        }

        if (isset($form['application'])) {
            $conditions['responder LIKE'] = $form['application'];
        } elseif (isset($form['responder'])) {
            $conditions['responder LIKE'] = $form['responder'];
        }

        if (isset($form['plan_description'])) {
            $conditions['plan_description LIKE'] = $form['plan_description'];
        }
        if (isset($form['to_user'])) {
            $conditions['to_user'] = $form['to_user'];
            if ($form['object'] == 'phonenumber' && substr( $form['to_user'], 0, 7 ) === "lookup:" ) {
              if (!isset($this->Subscriber)) {
                  $this->loadModel('Subscriber');
              }
              $validSubs = $this->Subscriber->getUsersBySrv(str_replace("lookup:","",$form['to_user']), $form['dest_domain']);
              $conditions['to_user'] = $validSubs;
            }
        }

        if ($form['object'] == 'phonenumber') {
            if (isset($form['to_host']) && $form['to_host'] != "") {
                $conditions['to_host'] = $form['to_host'];
            }
        }

        if ($form['object'] == 'phonenumber') {

            if (isset($form['enabled']) && $form['enabled'] != 'all') { //here
                if ($form['enabled'] == 'yes'){
                  $conditions['enable'] = "yes";
                } else {
                  $conditions['enable'] = "no";
                }

            }

            if (isset($form['dest_domain']) && $form['dest_domain'] != '')
            {
                $conditions['dialrule_domain'] = $form['dest_domain'];
            }
            elseif (isset($form['dialrule_domain']) && $form['dialrule_domain'] != '')
            {
                $conditions['dialrule_domain'] = $form['dialrule_domain'];
            }
            elseif (isset($form['territory']))
            {
                $joins = array(
                    array(
                        'table' => 'domains_config',
                        'alias' => 'Domain',
                        'type' => 'inner',
                        'conditions' => array(
                            'Domain.domain = dialrule_domain', 'Domain.territory' => $form['territory']
                        )
                    )
                );
                $conditions['joins'] = $joins;

            }

            if (isset($form['site']) && !isset($form['to_user']))
            {
                if (!isset($this->Subscriber)) $this->loadModel('Subscriber');
                $conditions['to_user'] = $this->Subscriber->getUsersInSite($form['site'], $form['dest_domain']);
            }

        }

        if ($form['object'] == 'phonenumber' && !isset($conditions['dialrule_domain'])) {
            $conditions['NOT']['OR'][0]['matchrule LIKE'] = '%?%';
            $conditions['NOT']['OR'][1]['matchrule LIKE'] = '%[%';
            $conditions['NOT']['OR'][2]['matchrule LIKE'] = '%*@%';
        }

        //Old Messy logic below. Keeping it around for a bit just in case.
        // if (isset($form['dest_domain']) ) {
        //     $conditions['OR'][0]['to_host'] = $form['dest_domain'];
        //     $conditions['OR'][1]['AND']['to_user LIKE'] = '%.'.$form['dest_domain'];
        //     $conditions['OR'][1]['AND']['to_host'] = 'conference-bridge';
        //     $conditions['OR'][2]['parameter'] = $form['dest_domain'];
        // }

        if ($form['object'] == 'dialplan') {
            if (isset($domain)) {
                $conditions['OR'][0]['domain'] = $domain;
                $conditions['OR'][1]['domain'] = 'admin-only';
                $conditions['OR'][2]['domain'] = '*';
            }
        } elseif (isset($domain)) {
            $conditions['domain'] = $domain;
        }
        if (isset($dialplan)) {
            $conditions['dialplan'] = $dialplan;
        }
        if (isset($matchrule)) {
            $conditions['matchrule'] = $matchrule;
        }
        $conditions['object'] = $form['object'];

       if (isset($form['sort']))
           $conditions['order'] = $form['sort'];

        return $conditions;
    }

    /**
     * Generates unique ids for database use
     * @return string
     */
    public function gen_uuid() {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 32 bits for "time_low"
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

            // 16 bits for "time_mid"
            mt_rand( 0, 0xffff ),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand( 0, 0x0fff ) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand( 0, 0x3fff ) | 0x8000,

            // 48 bits for "node"
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }


    /**
     * Process inbound brightlink form
     * @return newly formatted form
     */
    public function __ProcessBrightlinkInbound(&$form, $rawPost) {

        $this->nslog('messaging', 'this is in __ProcessBrightlinkInbound form: '.print_r($form, true));
        $rawPostOrig = $rawPost;

        $rawPost = preg_replace('/\s+/', '', $rawPost);
        $this->nslog('messaging', 'this is in __ProcessBrightlinkInbound rawPost: '.print_r($rawPost, true));
        $this->nslog('messaging', 'this is in __ProcessBrightlinkInbound rawPostOrig: '.print_r($rawPostOrig, true));

        $from = $this->get_string_between($rawPost, '<SenderAddress><Number>', '</Number></SenderAddress>');
        $base64Data = $this->get_string_between($rawPost, "base64", "-----mime");
        $text = $this->get_string_between($rawPostOrig, ".txt\r\n\r\n", "-----mime");

        // if message is in the form of an attachment text (for google voice support)
        if (strpos($rawPostOrig, 'Content-location: Attachment') !== false) {
            $text = $this->get_string_between($rawPostOrig, "Content-location: Attachment\r\n\r\n", "-----mime");
        }

        $dialed = $this->getDialedArrayBrightLinkGroupMMS($rawPost);

        $form['username'] = 'username';
        $form['password'] = 'password';
        $form['from'] = $from;
        $form['to'] = $dialed;
        $form['base64Data'] = $base64Data;
        $form['inboundSMS'] = true;
        $form['dialed'] = implode(",", $dialed);
        $form['text'] = $text;

        return;
    }
    private function __inboundBrightlinkMMSResp() {
      ##MMS resp XML
      $resp_xml = '<?xml version="1.0" encoding="UTF-8"?>
      <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/">
      <env:Header>
      <TransactionID xmlns="http://www.3gpp.org/ftp/Specs/archive/23_series/23.140/schema/REL-5-MM7-1-2" env:mustUnderstand="1">E3BBBADE07CBB2C3</TransactionID>
      </env:Header>
      <env:Body>
      <SubmitRsp xmlns="http://www.3gpp.org/ftp/Specs/archive/23_series/23.140/schema/REL-5-MM7-1-2">
      <MM7Version>5.3.0</MM7Version>
      <MessageID>idplz</MessageID>
      <StatusCode>1000</StatusCode>
      </SubmitRsp>
      </env:Body>
      </env:Envelope>
      ';

      $this->nslog('messaging', 'resp_xml inbound brightlink: '.print_r($resp_xml, true));
      return $resp_xml;
    }

    // if there is several cc'd numbers
    public function getDialedArrayBrightLinkGroupMMS($string){
        // $str = '<Number>+17142220353</Number></SenderAddress></SenderIdentification><Recipients><To><Number>+14842634001</Number></To><To><NumberdisplayOnly="true">+14842634005</Number><NumberdisplayOnly="true">+2222</Number>';
        $str = $string;
        $totalMatch = (array) null;

        preg_match_all('/<Cc><Number>(.*?)<\/Number>/', $str, $match2);
        if (isset($match2[1])) {
            $totalMatch = array_merge($totalMatch, $match2[1]);
        }

        preg_match_all('/<To><Number>(.*?)<\/Number>/', $str, $match3);
        if (isset($match3[1])) {
            $totalMatch = array_merge($totalMatch, $match3[1]);
        }

        $str = $string;
        preg_match_all('/NumberdisplayOnly="true">(.*?)<\/Number>/', $str, $match);
        // print_r($match);
        // match example ["+14842634005","+2222"]
        if (isset($match[1])) {
            $totalMatch = array_merge($totalMatch, $match[1]);
        }

        return array_unique($totalMatch);
    }


    public function get_string_between($string, $start, $end){
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    public function utf8(&$input)
    {
        if (is_string($input)) {
            $input = utf8_encode($input);
        } elseif (is_array($input)) {
            foreach ($input as &$value) {
                $this->utf8($value);
            }

            unset($value);
        } elseif (is_object($input)) {
            $vars = array_keys(get_object_vars($input));

            foreach ($vars as $var) {
                $this->utf8($input->$var);
            }
        }
    }

    public function safe_json_encode($value){
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            $encoded = json_encode($value, JSON_PRETTY_PRINT);
        } else {
            $encoded = json_encode($value);
        }
        //print_r($encoded);
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                return $encoded;
            case JSON_ERROR_DEPTH:
                return 'Maximum stack depth exceeded'; // or trigger_error() or throw new Exception()
            case JSON_ERROR_STATE_MISMATCH:
                return 'Underflow or the modes mismatch'; // or trigger_error() or throw new Exception()
            case JSON_ERROR_CTRL_CHAR:
                return 'Unexpected control character found';
            case JSON_ERROR_SYNTAX:
                return 'Syntax error, malformed JSON'; // or trigger_error() or throw new Exception()
            case JSON_ERROR_UTF8:
                $clean = $this->utf8ize($value);
                return $this->safe_json_encode($clean);

            default:
                return 'Unknown error'; // or trigger_error() or throw new


        }
    }

    public function __getHtmlTitle($strHtml)
    {
        $pMatches = array();
        if (preg_match('/<title>(.*?)<\/title>/', $strHtml, $pMatches)) {
            return $pMatches[1];
        } else {
            return '';
        }
    }


    public function utf8ize($mixed) {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = $this->utf8ize($value);
            }
        } else if (is_string ($mixed)) {
            $tmp = utf8_encode($mixed);
            json_encode($tmp);
            if (json_last_error() == JSON_ERROR_UTF8)
              return "";
            return $tmp;
        }
        return $mixed;
    }

    // For reseller read, returns an array of domains for a given territory
    public function __getDomainsInTerritory($territory) {
        $query['territory'] = $territory;
        $query['object'] = 'domain';

        if (!isset($this->Domain)) {
            $this->loadModel('Domain');
        }

        $postDomains = $this->Domain->nsRead($query);

        $retArr = array();

        foreach ($postDomains['xml']['domain'] as $idx => $data) {
            array_push($retArr, $data['domain']);
        }

        return $retArr;
    }


   public  function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
 }



  public function base64url_decode($data) {

    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));

  }

  public function extractRfc3339($field,&$form,&$time_options)
    {
        //https://regex101.com/r/664CA6/1 #my modified version
        //https://regex101.com/r/28guLD/4 #source
        preg_match('/(?=(?:^(?:\d\d\d\d-(?:0[1-9]|10|11|12)-(?:0[1-9]|1[0-9]|2[0-8])|\d\d\d\d-(?:0[13-9]|10|11|12)-(?:29|30)|\d\d\d\d-(?:0[13578]|10|12)-31|(?:\d\d[2468][048]|\d\d0[48]|\d\d[13579][26])-02-29|(?:[02468][048]00|[13579][26]00)-02-29)T(?:(?:0[0-9]|1[0-9]|2[0-3]):(?:[0-5][0-9]):(?:[0-5][0-9]))(?:\.\d\d\d)?(?:Z|[\+\-](?:0[0-9]|1[012]):00|\+0[34569]:30|\+10:30|-0[39]:30|\+1[34]:00|\+0[58]:45|\+12:45)(\[.+\])?$)|^(?:1972|198[1235]|199[2347]|2012|2015)-06-30T23:59:60Z(\[.+\])?$|^(?:197[2-9]|1987|1989|199[058]|2005|2008|2016)-12-31T23:59:60Z(\[.+\])?$)(?!.*-00:00(\[.+\])?$)^(\d\d\d\d)-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)(?:\.(\d\d\d))?((Z)|([\+\-])(\d\d):(\d\d))(\[.+\])?$/', $form[$field], $matches);
        
        if (isset($matches[5])) {
            $time_options['rfc3339']=true;
            $form[$field] = $matches[5]."-".$matches[6]."-".$matches[7]." ".$matches[8].":".$matches[9].":".$matches[10];
            $time_options['extracted_offset'] = $matches[12];
            $time_options['usedZ'] = false;
            if ($time_options['extracted_offset'] == "Z") 
            {
                $time_options['usedZ']  = true;
                $time_options['extracted_offset'] = "+00:00";
            }
            if (isset($matches[17])) {
                $time_options['extracted_timezone'] = str_replace("[","",str_replace("]","",$matches[17]));
                $form[$field] = $this->local2utc($form[$field],$time_options['extracted_timezone']);
            }

            $time_options['range_interval'] = str_replace(":00", " HOUR ", $time_options['extracted_offset']);
            if (substr($time_options['range_interval'], 0, 1) == "-")
                $time_options['range_interval'] = ltrim(str_replace("-", "", $time_options['range_interval']),"0");
            else if($time_options['extracted_offset']=="+00:00")
                unset($time_options['range_interval']);
            else 
                $time_options['range_interval'] = "-".ltrim(trim($time_options['range_interval']),"0");
            
        }
    }

    public function local2utc($local,$tz)
    {
        try{
            $local_tz = new DateTimeZone($tz);
            $local_dt = new DateTime($local, $local_tz);
            $local_dt->setTimezone(new DateTimeZone('UTC'));
            return $local_dt->format('Y-m-d H:i:s');
        }
        catch(Exception $e){
            $this->errorResponse(400, 'Issue with timezone conversion');
        } 
    }
    
    public function processTimes($form,&$time_options=array())
    {
        if (isset($form['start_date']) && $form['start_date'] =="") {
            unset($form['start_date']);
        }
        if (isset($form['end_date']) && $form['end_date'] =="") {
            unset($form['end_date']);
        }

        if (isset($form['start_date']) && strlen($form['start_date']) == 10) {
            $form['start_date'] .= ' 00:00:00';
        }
        if (isset($form['end_date']) && strlen($form['end_date']) == 10) {
            $form['end_date'] .= ' 23:59:59';
        }
        else if (isset($form['end_date']) && strlen($form['end_date']) == 16 && substr_count($form['end_date'], ':') < 2) {
            $form['end_date'] .= ':59';
        }

        
        $time_options=array("rfc3339"=>false);
        if ($this->isv2())
        {
            $this-> extractRfc3339('start_date',$form,$time_options);
            $this-> extractRfc3339('end_date',$form,$time_options);
        }
       
        if (!isset($form['start_date']) or !isset($form['end_date']) or
      !strtotime($form['start_date']) or !strtotime($form['end_date']) or
      strtotime($form['start_date']) >= strtotime($form['end_date'])
      ) {
            if ((isset($form['cdr_id']) || isset($form['limit'])) && (!isset($form['cdr_id']) || $form['cdr_id']!= "count")) {
                $form['start_date'] = gmdate('Y-m-d', time() - 5259487).' 00:00:00';
                $form['end_date'] = gmdate('Y-m-d', time()).' 23:59:59';
            } else {
                $this->errorResponse(400, 'Please provide the date fields by following Y-m-d H:i:s format ');
            }
          // $Date          = (new DateTime());
          // $startUnixTime = $Date->getTimestamp();
          // $startDate     = $Date->format('Ym');
          // $endDate       = $Date->modify('-1 month')->format('Y-m-d H:i:s');
        }

         if (isset($form['range_interval'])) {
            $d = new DateTime($form['start_date']);
            $d->modify($form['range_interval']);
            $form['start_date'] = $d->format('Y-m-d H:i:s');
            $d = new DateTime($form['end_date']);
            $d->modify($form['range_interval']);
            $form['end_date'] = $d->format('Y-m-d H:i:s');
            $this->nslog('debug', '('.$this->name.'.read_cdrs new range ) '.print_r($form['start_date'], true) ."-".$form['end_date']);
        }

        $this->set('time_options', $time_options);
        return $form;
    }

        /**
     * check if the scope parameter is higher than the logged in user's scope
     *
     * @param {string} $scope is the scope that will be checked against the user's
     **/
    public function isScopeHigher($baseScope, $isHigherScope, $equalTo = false) {
        $scopeList = $this->scopeList();
        $scopePosition = array_search($baseScope, $scopeList);
        $subScopePosition = array_search($isHigherScope, $scopeList);

        if($equalTo) {
            if ($scopePosition >= $subScopePosition)
                return true;
            else return false;
        } else {
            if ($scopePosition > $subScopePosition)
                return true;
            else return false;
        }
    }

    /**
     * return a list of all the scopes. scopes are ordered from lowest to highest permissions
     *
     * @param none
     **/
    public function scopeList() {
        $list = array(
            'Simple User',
            'Basic User',
            'Advanced User',
            'Call Center Agent',
            'Call Center Supervisor',
            'Site Manager',
            'Office Manager',
            'Reseller',
            'Super User'
        );

        return $list;
    }

    /**
     * Validate a user-supplied SQL INTERVAL expression.
     * Accepts only "N UNIT" where N is digits and UNIT is a recognised MySQL keyword.
     * Returns '0 HOUR' (a safe, neutral no-op) for any invalid input.
     */
    protected function __safeInterval($val)
    {
        if (!is_scalar($val)) {
            return '0 HOUR';
        }
        $trimmed = trim((string)$val);
        if (preg_match('/^-?\d+\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)$/i', $trimmed)) {
            return $trimmed;
        }
        return '0 HOUR';
    }

}
