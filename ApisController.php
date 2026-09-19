<?php

class ApisController extends AppController
{
    public $uses = array();
    public $layout = 'api';

    /* Map request object to controller, used for all generic passthrough.
     * If you're adding a new controller for a standard object, you can
     * likely just add it here and move on. */

    // NOW IN APPCONTROLLER!
    // public $endpointsMap = array(
    //     'account'            => 'accounts',
    //     'address'            => 'addresses',
    //     'agent'              => 'callqueues',
    //     'agentlog'           => 'callqueues',
    //     'ani'                => 'anis',
    //     'attendant'          => 'attendants',
    //     'attendee'           => 'meetings',
    //     'audio'              => 'music',
    //     'auditlog'           => 'auditlogs',
    //     'balance'            => 'balances',
    //     'bill'               => 'bills',
    //     'billinfo'           => 'billinfos',
    //     'call'               => 'calls',
    //     'callidemgr'         => 'callidemgrs',
    //     'callqueue'          => 'callqueues',
    //     'callqueueemailreport'    => 'callqueues',
    //     'callqueuereport'    => 'callqueues',
    //     'callqueuestat'      => 'callqueues',
    //     'cdr'                => 'cdrs',
    //     'cdr1'               => 'cdrs',
    //     'cdr2'               => 'cdrs2',
    //     'cdrexport'          => 'cdrExport',
    //     'cdrschedule'        => 'cdrSchedule',
    //     'charge'             => 'charges',
    //     'chart'              => 'charts',
    //     'conference'         => 'conferences',
    //     'conferencecdr'      => 'conferences',
    //     'connection'         => 'connections',
    //     'contact'            => 'contacts',
    //     'contacts'           => 'contacts',
    //     'dashboard'          => 'dashboards',
    //     'device'             => 'devices',
    //     'devicemodel'        => 'devices',
    //     'devicedefault'      => 'devices',
    //     'deviceprofile'      => 'deviceprofiles',
    //     'defaultvalue'       => 'settings',
    //     'dialplan'           => 'dialrules',
    //     'dialpolicy'         => 'dialpolices',
    //     'dialrule'           => 'dialrules',
    //     'disposition'        => 'callqueues',
    //     'domain'             => 'domains',
    //     'email'              => 'mailers',
    //     'elementdomain'      => 'settings',
    //     'event'              => 'events',
    //     'image'              => 'settings',
    //     'moh'                => 'music',
    //     'meeting'            => 'meetings',
    //     'ndpserver'          => 'devices',
    //     'ndpserverList'      => 'devices',
    //     'participant'        => 'conferences',
    //     'permission'         => 'dialpolices',
    //     'phoneconfiguration' => 'phoneconfigurations',
    //     'phonenumber'        => 'dialrules',
    //     'postrating'         => 'postratings',
    //     'prometheus'         => 'settings',
    //     'push'               => 'pushs',
    //     'queued'             => 'callqueues',
    //     'queuedcall'         => 'callqueues',
    //     'quotaUsage'         => 'quotas',
    //     'quota'              => 'quotas',
    //     'rateplan'           => 'rateplans',
    //     'rechargecard'       => 'rechargecards',
    //     'reseller'           => 'resellers',
    //     'serviceplan'        => 'serviceplans',
    //     'setting'            => 'settings',
    //     'sfu'                => 'sfu',
    //     'speechcommand'      => 'speechcommand',
    //     'statistics'         => 'statistics',
    //     'timeframe'          => 'timeframes',
    //     'timerange'          => 'timeranges',
    //     'turn'               => 'turns',
    //     'trace'              => 'traces',
    //     'uiconfig'           => 'settings',
    //     'uiconfigdef'        => 'settings',
    //     'voice'              => 'voice',
    //     'voicemail'          => 'music',
    //     'upload'             => 'upload',
    //     'route'              => 'routes',
    //     'routecon'           => 'routes'
    //     'pwa'           => 'pwa'
    // );
    
    /**
     * Dispatcher.
     *
     * If the specified action exists in the specified object's controller, invoke it;
     * otherwise, return an HTTP response status code of 404 (Not Found).
     *
     * After successful execution of the invoked action, the controller's corresponding
     * view code in index.ctp will be executed to generate any requested data returned
     * with an HTTP response status code of 200 (OK).
     */
    public function dispatch()
    {
        $start = microtime(true);

        $this->sendOriginHeaders();

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
        } else {
            $this->errorResponse(404, 'Unable to map request.');
        }

        if (isset($form['action']) && $form['action']!="processQueuedSMS")
          $this->nslog('debug', '('.$this->name.'.dispatch) form '.print_r($form, true));

        // custom endpoint handlers are declared here, otherwise use endpointsMap passthrough
        switch ($form['object']) {
            /*
             * @api {post} ?object=version  View API version
             * @apiName ReadVersion
             * @apiGroup Version
             * @apiSampleRequest ?format=json&object=version
             */
            case 'version':
                $response = array('apiversion' => Configure::read('Version'));
                echo json_encode($response);
                exit;
                break;

            case 'conf_part':
                $this->errorResponse(412, 'Precondition Failed.');
                exit;
                break;

            case 'mac':
                if ($form['action'] == 'read' || $form['action'] == 'count' || $form['action'] == 'create' || $form['action'] == 'update' || $form['action'] == 'delete') {
                    echo $this->requestAction(
                        array( 'controller' => 'devices', 'action' => 'index'),
                        array( 'return', 'data' => array('form' => $form))
                    );
                } else {
                    echo $this->requestAction(
                        array( 'controller' => 'devices', 'action' => 'index'),
                        array( 'return', 'data' => array('form' => $form))
                    );
                }
                exit;
                break;

            case 'phaxios':
            case 'fax':
            case 'phaxio':
                $this->nslog('debug', '('.$this->name.'.dispatch) form2 '.print_r($form, true));

                if ($form['action'] == 'upload' || $form['action'] == 'read' || $form['action'] == 'count' || $form['action'] == 'list' || $form['action'] == 'create'|| $form['action'] == 'update' || $form['action'] == 'delete') {
                    echo $this->requestAction(array('controller' => 'faxes', 'action' => 'index', 'form' => $form), array('return'));
                } elseif ($form['action'] == 'update') {
                    echo $this->requestAction(array('controller' => 'faxes', 'action' => 'update', 'form' => $form), array('return'));
                } elseif ($form['action'] == 'cron') {
                    echo $this->requestAction(array('controller' => 'faxes', 'action' => 'cron'), array('return'));
                } elseif ($form['action'] == 'inbound') {
                    echo $this->requestAction(array('controller' => 'faxes', 'action' => 'inbound'), array('return'));
                } else {
                    echo $this->requestAction(array('controller' => 'faxes', 'action' => 'create', 'form' => $form), array('return'));
                }

                exit;
                break;

            case 'rhub':

                $this->nslog('debug', '('.$this->name.'.dispatch) rhubs'.print_r($form, true));
                if ($form['action'] == 'signin') {
                    $this->nslog('debug', '('.$this->name.'.dispatch) rhubs2'.print_r($form, true));
                    echo $this->requestAction(array('controller' => 'rhubs', 'action' => 'signin', $form['?email'], $form['password']), array('return'));
                } else {
                    echo $this->requestAction(array('controller' => 'rhubs', 'action' => 'index', 'form' => $form), array('return'));
                }
                exit;
                break;

            case 'videoguest':
            case 'video':
                $this->nslog('debug', '('.$this->name.'.dispatch videoguest) video'.print_r($form, true));
                if ($form['action'] == 'read') {
                    echo $this->requestAction(array('controller' => 'video', 'action' => 'read', 'form' => $form), array('return'));
                } elseif ($form['action'] == 'create') {
                    echo $this->requestAction(array('controller' => 'video', 'action' => 'create', 'form' => $form), array('return'));
                } else {
                    echo $this->requestAction(array('controller' => 'video', 'action' => 'index', 'form' => $form), array('return'));
                }
                exit;
                break;

            case 'answerrule':
            case 'department':
            case 'presence':
            case 'sso':
            case 'mfa':
            case 'subscriber':
            case 'subscriberani':
            case 'timezone':
            case 'vmailnag':
                echo $this->requestAction(array('controller' => 'subscribers', 'action' => 'index', 'form' => $form), array('return'));
                $end = microtime(true);
                $this->nslog('debug', '(dispatch)  time='.$this->_printMS($start, $end).'ms');

                exit;
                break;

            case 'site':
                if ($form['action'] == 'read' || $form['action'] == 'count')
                    echo $this->requestAction(array('controller' => 'sites', 'action' => 'index', 'form' => $form), array('return'));
                else
                    echo $this->requestAction(array('controller' => 'subscribers', 'action' => 'index', 'form' => $form), array('return'));

                exit;
                break;

            case 'addressendpoints':
                echo $this->requestAction(
                    array(
                        'controller' => 'addresses',
                        'action' => 'index'
                    ),
                    array(
                        'return',
                        'data' => array(
                            'form' => $form
                        )
                    )
                );

            case 'messagesession':
            case 'conversation':
            case 'smsnumber':
                if (isset($form['action']) && $form['action'] == 'ations') {
                    $postdata = file_get_contents('php://input');
                    $this->nslog('messaging', '('.$this->name.'.dispatch) postdata '.print_r($postdata, true));
                    $this->nslog('messaging', '('.$this->name.'.dispatch) _POST '.print_r($_POST, true));

                    echo $this->requestAction(array('controller' => 'messages', 'action' => 'index', 'form' => $form), array('return'));
                    exit;
                }

                if (!isset($form['action']) || ($form['action'] == 'create' && !isset($form['message']))) {
                    $this->nslog('debug', '('.$this->name.'.dispatch) _SERVER '.print_r($_SERVER, true));
                    $this->nslog('debug', '('.$this->name.'.dispatch) form '.print_r($form, true));

                    if (isset($_SERVER['HTTP_USER_AGENT'])) {
                        $this->nslog('debug', '('.$this->name.".dispatch) _SERVER['HTTP_USER_AGENT'] ".print_r($_SERVER['HTTP_USER_AGENT'], true));
                    }

                    $postdata = file_get_contents('php://input');
                    $this->nslog('debug', '('.$this->name.'.dispatch) postdata '.print_r($postdata, true));

                    $josnData = json_decode($postdata, true);
                    $this->nslog('debug', '('.$this->name.'.dispatch) josnData '.print_r($josnData, true));
                    $this->nslog('debug', '('.$this->name.'.dispatch) _POST '.print_r($_POST, true));

                    if (isset($form['SmsMessageSid'])) {
                        $form['inboundSMS'] = 'true';
                    } elseif (is_array($josnData)) {
                        $josnData['object'] = $form['object'];
                        $josnData['action'] = 'create';
                        $josnData['inboundSMS'] = 'true';
                        $form = $josnData;
                    } elseif (isset($_POST['inboundSMSMessageation'])) {
                        $josnData = $_POST;
                        $josnData['object'] = $form['object'];
                        $josnData['action'] = 'create';
                        $josnData['inboundSMS'] = 'true';

                        $josnData['action'] = 'create';
                        $josnData['object'] = 'sms';

                        $form = $josnData;
                    }
                } else if (isset($form['phonenumber']) && !isset($form['uid']) && is_array($_SERVER) && !array_key_exists('HTTP_AUTHORIZATION', $_SERVER)) {
                    $form['inboundSMS'] = 'true';
                } //brightlink else if to/text and HTTP_AUTHORIZATION so we can bypass the 404 require

                $this->nslog('debug', '('.$this->name.'.dispatch) this->request '.print_r($form, true));


                echo $this->requestAction(
                    array(
                        'controller' => 'messages',
                        'action' => 'index'
                    ),
                    array(
                        'return',
                        'data' => array(
                            'form' => $form
                        )
                    )
                );

                exit;
                break;

            case 'sms':
            case 'message':
                if (isset($form['action']) && $form['action'] == 'notifications') {
                    $postdata = file_get_contents('php://input');
                    $this->nslog('messaging', '('.$this->name.'.notifications) postdata '.print_r($postdata, true));
                    $this->nslog('messaging', '('.$this->name.'.notifications) _POST '.print_r($_POST, true));
                    $this->nslog('messaging', '('.$this->name.'.notifications) form '.print_r($form, true));

                    //for now just log and return 200ok.
                    exit;

                }

                if (isset($form['action']) && $form['action'] == 'processQueuedSMS') {
                    echo $this->requestAction(array('controller' => 'messages', 'action' => 'processQueuedSMS', 'form' => $form), array('return'));
                    exit;
                }


                if (!isset($form['action']) || ($form['action'] == 'create' && !isset($form['message']))) {
                    $this->nslog('debug', '('.$this->name.'.dispatch) _SERVER '.print_r($_SERVER, true));
                    $this->nslog('debug', '('.$this->name.'.dispatch) form '.print_r($form, true));

                    if (isset($_SERVER['HTTP_USER_AGENT'])) {
                        $this->nslog('debug', '('.$this->name.".dispatch) _SERVER['HTTP_USER_AGENT'] ".print_r($_SERVER['HTTP_USER_AGENT'], true));
                    }

                    $postdata = file_get_contents('php://input');
                    $this->nslog('debug', '('.$this->name.'.dispatch) postdata '.print_r($postdata, true));

                    $josnData = json_decode($postdata, true);
                    $this->nslog('debug', '('.$this->name.'.dispatch) josnData '.print_r($josnData, true));
                    $this->nslog('debug', '('.$this->name.'.dispatch) _POST '.print_r($_POST, true));

                    if (isset($form['SmsMessageSid'])) {
                        $form['inboundSMS'] = 'true';
                    } elseif (is_array($josnData)) {
                        $josnData['object'] = $form['object'];
                        $josnData['action'] = 'create';
                        $josnData['inboundSMS'] = 'true';
                        $form = $josnData;
                    } elseif (isset($_POST['inboundSMSMessageNotification'])) {
                        $josnData = $_POST;
                        $josnData['object'] = $form['object'];
                        $josnData['action'] = 'create';
                        $josnData['inboundSMS'] = 'true';

                        $josnData['action'] = 'create';
                        $josnData['object'] = 'sms';

                        $form = $josnData;
                    }  elseif (isset($form['teliid'])) {
                        $form['inboundSMS'] = 'true';
                        $form['to'] = (!empty($_POST['destination'])) ? $_POST['destination'] : '';
                        $form['from'] = (!empty($_POST['source'])) ? $_POST['source'] : '';
                        $form['text'] = (!empty($_POST['message'])) ? $_POST['message'] : '';
                    }
                    elseif (isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] == "thinq-sms") {
                        $josnData = $_POST;
                        $josnData['inboundSMS'] = 'true';

                        $josnData['action'] = 'create';
                        $josnData['object'] = 'sms';
                        $josnData['user_agent'] = "thinq-sms";

                        $form = $josnData;
                    } else if (isset($_POST['username']) && isset($_POST['password'])) {
                      $josnData = $_POST;
                      $josnData['object'] = $form['object'];
                      $josnData['action'] = 'create';
                      $josnData['inboundSMS'] = 'true';

                      $form = $josnData;
                    }
                } else if (isset($form['phonenumber']) && !isset($form['uid']) && is_array($_SERVER) && !array_key_exists('HTTP_AUTHORIZATION', $_SERVER)) {
                    $form['inboundSMS'] = 'true';
                }

                if (!isset($form['inboundSMS']))
                  $form['inboundSMS'] = false;
                $this->nslog('debug', '('.$this->name.'.dispatch) this->request '.print_r($form, true));

                echo $this->requestAction(
                    array(
                        'controller' => 'messages',
                        'action' => 'index'
                    ),
                    array(
                        'return',
                        'data' => array(
                            'form' => $form,
                            'inboundSMS' =>   $form['inboundSMS']
                        )
                    )
                );

                exit;
                break;

            case 'token':
                if ($form['action'] == 'read') {
                    echo $this->requestAction(array('controller' => 'oauth2', 'action' => 'read', 'form' => $form), array('return'));
                    exit;
                }
                else if ($form['action'] == 'checkToken') {
                    echo $this->requestAction(array('controller' => 'oauth2', 'action' => 'checkToken', 'form' => $form), array('return'));
                    exit;
                } else {
                    $this->nslog('debug', '('.$this->name.'.dispatch) not allowed token action - form '.print_r($form, true));
                    $this->errorResponse(404, 'Bad request. Unroutable action.');
                }
                break;

            case 'upload':
                $this->nslog('debug', '('.$this->name.'.dispatch) form2 '.print_r($form, true));
                //die('hre'.file_get_contents('php://input'));

                if (in_array($form['action'], array('upload','create','read_configuration'))) {
                    echo $this->requestAction(
                        array(
                            'controller' => 'upload',
                            'action' => 'index'
                        ),
                        array(
                            'return',
                            'data' => $form
                        )
                    );
                } else {
                    $this->errorResponse(405, 'Method not allowed');
                }

                exit;
                break;

            case 'oauth':
                if ($form['action'] == 'ssoEnroll') {
                    echo $this->requestAction(array('controller' => 'oauth2', 'action' => 'ssoEnroll', 'form' => $form), array('return'));
                    exit;
                } else if ($form['action'] == 'genAuthenticator') {
                    echo $this->requestAction(array('controller' => 'oauth2', 'action' => 'genAuthenticator', 'form' => $form), array('return'));
                    exit;
                }  else if ($form['action'] == 'mfaEnroll') {
                    echo $this->requestAction(array('controller' => 'oauth2', 'action' => 'mfaEnroll', 'form' => $form), array('return'));
                    exit;
                }  else if ($form['action'] == 'checkToken') {
                    echo $this->requestAction(array('controller' => 'oauth2', 'action' => 'checkToken', 'form' => $form), array('return'));
                    exit;   
                } else {
                    $this->nslog('debug', '('.$this->name.'.dispatch) not allowed token action - form '.print_r($form, true));
                    $this->errorResponse(404, 'Bad request. Unroutable action.');
                }
                break;

             /* Using explicit recording route to avoid auto-discovery of sbus channel route which
              *  'accidentally' shares the same name as the controller. The sbus channel route
              *  is declaured in Config/routes.php: Router::connect('/recordings'..... */

            case 'licf_lea':
            case 'recording':
            case 'recordingstorage':
                echo $this->requestAction(array('controller' => 'recordings', 'action' => 'index', 'form' => $form), array('return'));
                exit;
                break;

            case 'callrequest':
                echo $this->requestAction(array('controller' => 'callrequests', 'action' => 'index', 'form' => $form), array('return'));
                exit;
                break;

            case 'pwa': 
                $this->nslog('debug', 'in apis controller pwa');
                echo $this->requestAction(
                    array(
                        'controller' => 'pwa',
                        'action' => 'index'
                    ),
                    array(
                        'return',
                        'data' => array(
                            'form' => $form
                        )
                    )
                );

                exit;
                break;
    
            default:
                if( array_key_exists( $form['object'], $this->endpointsMap ) ) { // if the requested object has a known map
                    echo $this->requestAction(
                        array(
                            'controller' => $this->endpointsMap[$form['object']],
                            'action' => 'index'
                        ),
                        array(
                            'return',
                            'data' => array(
                                'form' => $form
                            )
                        )
                    );
                } else { // if an unknown object was requested
                    $this->nslog('debug', '('.$this->name.'.dispatch) not match  - form '.print_r($form, true));
                    $this->errorResponse(404, 'Bad request. Unknown object.');
                }
                exit;
                break;
        }
    }

    //'Oauthclient','Oauthcode','Oauthtoken','Oauthlog'

    public function eventCreate($event)
    {
        $this->nslog('debug', '('.$this->name.'.eventCreate) event '.print_r($event, true));

        if (!isset($event['object'])) {
        } elseif ($event['object'] == 'oauth_code') {
            if (isset($event['code']) && isset($event['username']))
            {
              Cache::config('_auth_code_', array('duration' => Configure::read('NsAuthCodeExpire')));
              Cache::write(strtolower ($event['username']),$event['code'], '_auth_code_');
            }
            $this->loadModel('Oauthcode');
            $this->loadModel('Oauthlog');
            $this->Oauthcode->nsCreateFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'oauth_token') {
            $this->loadModel('Oauthtoken');
            $this->loadModel('Oauthlog');
            $this->Oauthtoken->nsCreateFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'oauth_client') {
            $this->loadModel('Oauthclient');
            $this->loadModel('Oauthlog');
            $this->Oauthclient->nsCreateFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'mfa') {
            $this->loadModel('MultifactorAuth');
            $this->MultifactorAuth->nsCreateFromSbus($event);
        } elseif ($event['object'] == 'sso') {
            $aor_user = $this->NsStringEscape($event['aor_user']);
            $aor_host = $this->NsStringEscape($event['aor_host']);
            $sso_id   = $this->NsStringEscape($event['sso_id']);
            $vendor   = $this->NsStringEscape($event['vendor']);
            $sql = "INSERT INTO `SiPbxDomain`.`subscriber_sso` (`aor_user`, `aor_host`, `sso_id`, `vendor`)
                    VALUES ('{$aor_user}','{$aor_host}','{$sso_id}','{$vendor}')
                    ON DUPLICATE KEY UPDATE `modified` = NOW();";

            $this->loadModel('Sso');
            $data = $this->Sso->query($sql);
        } elseif ($event['object'] == 'apikey') {
            $this->loadModel('Apikey');
            $this->Apikey->nsCreateFromSbus($event);
        }

        return true;
    }

    public function eventUpdate($event)
    {
        $this->nslog('debug', '('.$this->name.'.eventUpdate) event '.print_r($event, true));

        if (!isset($event['object'])) {
        } elseif ($event['object'] == 'oauth_code') {
            $this->loadModel('Oauthcode');
            $this->loadModel('Oauthlog');
            $this->Oauthcode->nsUpdateFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'oauth_token') {
            $this->loadModel('Oauthtoken');
            $this->loadModel('Oauthlog');
            $this->Oauthtoken->nsUpdateFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'oauth_client') {
            $this->loadModel('Oauthclient');
            $this->loadModel('Oauthlog');
            $this->Oauthclient->nsUpdateFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'mfa') {
            $this->loadModel('MultifactorAuth');
            $this->MultifactorAuth->nsUpdateFromSbus($event);
        } elseif ($event['object'] == 'apikey') {
            $this->loadModel('Apikey');
            $this->Apikey->nsUpdateFromSbus($event);
            $this->Apikey->revokeCache($event); // revoke cache, but this will not delete it, just removes from memory to avoid stale data
        }

        return true;
    }

    public function eventDelete($event) 
    {
        $this->nslog('debug', '('.$this->name.'.eventDelete) event '.print_r($event, true));

        if (!isset($event['object'])) {
        } elseif ($event['object'] == 'oauth_code') {
            $this->loadModel('Oauthcode');
            $this->loadModel('Oauthlog');
            $this->Oauthcode->nsDeleteFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'oauth_token') {
            $this->loadModel('Oauthtoken');
            $this->loadModel('Oauthlog');
            if (isset($event['token']))
                Cache::delete("oauth_token_access_".$event['token'], '_oauth_token_');
            $this->Oauthtoken->nsDeleteFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'oauth_client') {
            $this->loadModel('Oauthclient');
            $this->loadModel('Oauthlog');
            $this->Oauthclient->nsDeleteFromSbus($event);
            $this->Oauthlog->insertLog($event);
        } elseif ($event['object'] == 'mfa') {
            $this->loadModel('MultifactorAuth');
            $this->MultifactorAuth->nsDeleteFromSbus($event);
        } elseif ($event['object'] == 'apikey') {
            $this->loadModel('Apikey');
            
            $this->Apikey->nsDeleteFromSbus($event);
            $this->Apikey->revokeCache($event);
        } elseif ($event['object'] == 'jwt') {
            $this->loadModel('OauthJwt');
            if (isset($event['jti']))
                $this->OauthJwt->revokeJti($event['jti'],true);    
            if (isset($event['uid']))
                $this->OauthJwt->revokeByUid($event['uid'],true);
        }

        return true;
    }
}
