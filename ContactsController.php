<?php

App::import('Controller', 'Video'); // Import the Video controller

class ContactsController extends AppController
{
    public $uses = array('Contact','Domain','Subscriber');
    public $layout = 'api';

    public function read($form)
    {
        if ($form['object'] === 'contact') {
            $this->read_contact($form);
        } else {
            $this->errorResponse(400, 'Unsupported request object.');
        }
    }

    /**
     * @api {post} ?object=contact&action=read Read Contacts
     * @apiName Read
     * @apiGroup Contact
     * @apiHeader {String} Authorization Authorization value. Generally "Bearer ACCESSTOKEN".
     * @apiParam {String} domain        Identifies Domain from which to read Contacts.
     * @apiParam {String} user          Identifies User from which to read Contacts.
     * @apiParam {String} [first_name]  Filters search by Contacts' first name.
     * @apiParam {String} [last_name]   Filters search by Contact's last_name.
     * @apiParam {String} [tags] Ability to search by a specific tag or group of contacts.
     * @apiParam {Boolean} [includeDomain] (boolean, true/false) Include the presence data set for non residential domains.
     * @apiParam {String} [limit]       Specifies limit for number of Contacts returned.
     * @apiParam {String} [department]  Filters search by Contacts' department.
     * @apiParam {String} [order]       Specifies parameter by which the output is ordered.
     * @apiParam {String} [contact_id]      Specific id of the contact to be read.
     * @apiSuccess {String} domain
     * @apiSuccess {String} user
     * @apiSuccess {String} last_name
     * @apiSuccess {String} first_name
     * @apiSuccess {String} middle_name
     * @apiSuccess {String} company
     * @apiSuccess {String} work_phone
     * @apiSuccess {String} cell_phone
     * @apiSuccess {String} email
     * @apiSuccess {String} home_phone
     * @apiSuccess {String} tags
     * @apiSuccess {String} ts
     * @apiPermission Reseller
     * @apiSampleRequest ?format=json&object=contact&action=read
     */
    public function read_contact($form)
    {
        $this->nslog('debug', 'read_contact->$form: '.print_r($form, true));
        $this->set('preferedFieldOrder', $this->Contact->preferedFieldOrder);
        //if the contact_id is set then will only be receiving one contact
        if (isset($form['contact_id'])) {
            $query['conditions']['contact_id'] = $form['contact_id'];
            $query['order'] = array();
            $query['order']['last_name'] = 'ASC';
            $posts = $this->Contact->find('all', $query, true);
            $this->nslog('debug', 'read_contact->$posts: '.print_r($posts, true));

            if (is_array($posts) && empty($posts)) {
                // $posts is an empty array
                $this->set('results', []);
                return;
            }

            //building results array
            $results['xml'] = array();
            $results['xml']['contact'][0] = $posts[0]['Contact'];
            $this->nslog('debug', '('.$this->name.'.results) form '.print_r($results, true));
            $this->set('results', $results);
        } else { //old style of reading the contact when doing an edit
                 //can get all the contacts of a particular user and domain

            $this->validateFormHasUid($form);

            $contactsQuery['conditions']['domain'] = $form['domain'];
            $contactsQuery['conditions']['user'] = $form['user'];

            $contactsQuery['fields'] = 'last_name,first_name,middle_name,company,work_phone,cell_phone,fax,email,home_phone,tags,ts,contact_id';

            if (isset($form['first_name']))
                $contactsQuery['conditions']['first_name'] = $form['first_name'];

            if (isset($form['last_name']))
                $contactsQuery['conditions']['last_name'] = $form['last_name'];

            if (isset($form['tags']))
                $contactsQuery['conditions']['tags LIKE'] = '%'.$form['tags'].'%';

            // get personal contacts
            $contacts = $this->Contact->find('all', $contactsQuery);

            $results['xml'] = array();
            $subscriberCounter = 0;
            if (isset($form['includeDomain']) && ($form['includeDomain'] == 'yes' || $form['includeDomain'] == '1' || $form['includeDomain'] == true)) {

                $domainQuery['conditions']['domain'] = $form['domain'];
                $postDomain = $this->Domain->find('first',$domainQuery);

                if (isset($postDomain['Domain']) && $postDomain['Domain']['resi'] != 'yes') {
                    $subscriberQuery['conditions']['aor_host'] = $form['domain'];
                    $subscriberQuery['conditions']['domain_dir'] = 'yes';
                    $subscriberQuery['conditions']['aor_user !='] = 'domain';
                    $subscriberQuery['conditions']['NOT'][]['srv_code LIKE'] = 'system-%';
                    $subscriberQuery['conditions']['directory_match !='] = 'departments';

                    $subscriberQuery['fields'] = 'firstname,lastname,subscriber_group,site,aor_user,aor_host,presence,email_address,portal_status';

                    $this->nslog('debug', '('.$this->name.'.read_contact) subscriberQuery '.print_r($subscriberQuery, true));

                    if (isset($form['first_name']))
                        $subscriberQuery['conditions']['firstname'] = $form['first_name'];

                    if (isset($form['last_name']))
                        $subscriberQuery['conditions']['lastname'] = $form['last_name'];

                    if (isset($form['department']))
                        $subscriberQuery['conditions']['subscriber_group'] = $form['department'];

                    if (isset($form['site']))
                        $subscriberQuery['conditions']['site'] = $form['site'];

                    // get on-net subscribers
                    $subscribers = $this->Subscriber->find('all', $subscriberQuery);

                    $this->nslog('debug', '('.$this->name.'.read_contact) subscribers '.print_r($subscribers, true));

                    foreach ($subscribers as $subscriber) {
                        $thisSub = $subscriber['Subscriber'];
                        $result = array(
                            'first_name' => $thisSub['firstname'],
                            'last_name'  => $thisSub['lastname'],
                            'domain'     => $thisSub['aor_host'],
                            'group'      => $thisSub['subscriber_group'],
                            'site'       => $thisSub['site'],
                            'user'       => $thisSub['aor_user'],
                            'uid'        => $thisSub['aor_user'].'@'.$thisSub['aor_host'],
                            'email'      => $thisSub['email_address'],
                            'presence'   => $thisSub['presence']
                        );

                        if (isset($thisSub['portal_status'])) {
                            $result['message'] = $thisSub['portal_status'];
                        }

                        // loop through personal contacts and graft any matches onto the subscriber
                        foreach ($contacts as $key => $contact) {
                            $contact = $contact['Contact'];
                            $isOnnet = strpos($contact['tags'], 'ext') !== false;
                            $noTags  = strpos($contact['tags'], 'ext') === false && strpos($contact['tags'], 'post') === false;

                            if( ( $isOnnet || $noTags ) && $thisSub['firstname'] == $contact['first_name'] && $thisSub['lastname'] == $contact['last_name'] ) {
                                if (isset($contact['work_phone']) && $contact['work_phone'])
                                    $result['work_phone'] = $contact['work_phone'];

                                if (isset($contact['cell_phone']) && $contact['cell_phone'])
                                    $result['cell_phone'] = $contact['cell_phone'];

                                if (isset($contact['home_phone']) && $contact['home_phone'])
                                    $result['home_phone'] = $contact['home_phone'];

                                if (isset($contact['fax']) && $contact['fax'])
                                    $result['fax'] = $contact['fax'];

                                if (isset($contact['email']) && $contact['email'])
                                    $result['email'] = $contact['email'];
                                if (isset($contact['contact_id']) && $contact['contact_id'])
                                    $result['contact_id'] = $contact['contact_id'];


                                $result['tags'] = isset($contact['tags']) ? $contact['tags'] : null;
                                $result['contact_id'] = $contact['contact_id'];

                                unset($contacts[$key]); // this contact was mapped to a subscriber, so drop it from the contact array
                            }
                        }

                        $results['xml']['contact'][] = $result;
                    }
                }
            }

            // get shared contacts for domain, append to $contacts array
            //need to block this off through the ui_config PORTAL_SHOW_SHARED_CONTACTS
            if (!isset($this->Uiconfig)) {
                $this->loadModel('Uiconfig');
            }
            if ($this->Uiconfig->isUiConfig('PORTAL_SHOW_SHARED_CONTACTS', 'yes')){
              $sharedQuery['conditions']['user'] = 'domain';
              $sharedQuery['conditions']['domain'] = $form['domain'];

              if (isset($form['first_name']))
                  $sharedQuery['conditions']['first_name'] = $form['first_name'];

              if (isset($form['last_name']))
                  $sharedQuery['conditions']['last_name'] = $form['last_name'];

              $sharedQuery['fields'] = 'last_name,first_name,middle_name,company,work_phone,cell_phone,fax,email,home_phone,tags,ts,contact_id';
              $sharedContacts = $this->Contact->find('all', $sharedQuery);
              foreach ($sharedContacts as &$contact) {
                  $contact['Contact']['shared'] = true;
                  array_push($contacts, $contact);
              }
            }

            // add all unmapped contacts to the result set
            foreach ($contacts as $contact) {
                $results['xml']['contact'][] = $contact['Contact'];
            }

            // some custom sort logic here because we apply sorting on complete dataset, rather than on db queries
            $sortOrder = !isset($form['sort'])
                ? 'first_name ASC,last_name ASC,user ASC' // default sort order
                : str_replace("User.","",$form['sort']);
            $sortOrder = explode(',', $sortOrder);
            foreach( $sortOrder as $sortCondition ) {
                list($field, $direction) = explode(' ', $sortCondition);

                $this->mergesort( $results['xml']['contact'], function($a, $b) use ($field, $direction) {
                    $firstField = $direction == 'desc' ? @$b[$field] : @$a[$field];
                    $secondField = $direction == 'desc' ? @$a[$field] : @$b[$field];

                    if( !$firstField ) $firstField = 'ZZZZZZZz';
                    if( !$secondField ) $secondField = 'ZZZZZZZz';

                    return strcasecmp( $firstField, $secondField );
                });
            }

            // return empty array if contact results is null, was returning empty string ''
            if ($results['xml']['contact'] == null) {
                $results['xml']['contact'] = [];
            }
            
            // remove any repeat contact_id results
            $uniqueContacts = [];
            $existingContactIds = [];

            // Iterating through $results['xml']['contact'] to filter out duplicates
            foreach ($results['xml']['contact'] as $contact) {
                if (!isset($contact['contact_id']))
                {
                    //this is likely on net coworker, so just add it
                    $uniqueContacts[] = $contact;
                }
                // Checking if the contact_id exists in the existingContactIds array
                else if (!in_array($contact['contact_id'], $existingContactIds)) {
                    // If not, add it to the $uniqueContacts array
                    $uniqueContacts[] = $contact;
                    // Also add the contact_id to the existingContactIds array for tracking
                    $existingContactIds[] = $contact['contact_id'];
                }
            }

            if ($this->isv2())
            {
                if (isset($form['start'])) {
                    $conditions['start'] = $form['start'];
                }
                else {
                    $conditions['start'] = 0;
                }
                if (isset($form['limit'])) {
                    $conditions['limit'] = $form['limit'];
                }
                else {
                    $conditions['limit'] = 100;
                }

                $results['xml']['contact'] = array_slice($uniqueContacts, $conditions['start'], $conditions['limit']);
            }
            else
            {
                // Replace the original contact array with unique contacts
                $results['xml']['contact'] = $uniqueContacts;
            }
            



            

            $this->set('results', $results);
        }
    }

    // needed a custom msort function for read_contacts because usort is not a stable sort
    private function mergesort(&$array, $cmp_function = 'strcmp') {

        if (!isset($array) || !is_array($array)) return;
        // Arrays of size < 2 require no action.
        if (count($array) < 2) return;
        // Split the array in half
        $halfway = count($array) / 2;
        $array1 = array_slice($array, 0, $halfway);
        $array2 = array_slice($array, $halfway);
        // Recurse to sort the two halves
        $this->mergesort($array1, $cmp_function);
        $this->mergesort($array2, $cmp_function);
        // If all of $array1 is <= all of $array2, just append them.
        if (call_user_func($cmp_function, end($array1), $array2[0]) < 1) {
            $array = array_merge($array1, $array2);
            return;
        }
        // Merge the two sorted arrays into a single sorted array
        $array = array();
        $ptr1 = $ptr2 = 0;
        while ($ptr1 < count($array1) && $ptr2 < count($array2)) {
            if (call_user_func($cmp_function, $array1[$ptr1], $array2[$ptr2]) < 1) {
                $array[] = $array1[$ptr1++];
            }
            else {
                $array[] = $array2[$ptr2++];
            }
        }
        // Merge the remainder
        while ($ptr1 < count($array1)) $array[] = $array1[$ptr1++];
        while ($ptr2 < count($array2)) $array[] = $array2[$ptr2++];
        return;
    }

    /**
     * @api {post} ?object=contact&action=count Count Contacts
     * @apiName Count
     * @apiGroup Contact
     * @apiHeader {String} Authorization Authorization value. Generally "Bearer ACCESSTOKEN".
     * @apiParam {String} domain            Identifies Domain from which to count Contacts.
     * @apiParam {String} user              Identifies User from which to count Contacts.
     * @apiParam {String} [first_name]      Filters count based on Caontacts' first name.
     * @apiParam {String} [last_name]       Filters count based on Caontacts' last name.
     * @apiParam {String} [contact_id]      Contact_id hash of the contact.
     * @apiSuccess {String} total   Total number of Contacts in the specified Domain.
     * @apiPermission Reseller
     * @apiSampleRequest ?format=json&object=contact&action=count
     */
    public function count($form)
    {
        if (isset($form['contact_id']) && $form['contact_id'] !== '') {
          $query['conditions']['contact_id'] = $form['contact_id'];
          $posts = $this->Contact->find('count', $query);
          $pPosts['xml']['total'] = $posts;

          $this->set('results', $pPosts);
        } else {
          $this->validateFormHasUid($form);

          $query['conditions']['domain'] = $form['domain'];
          $query['conditions']['user'] = $form['user'];

          if (isset($form['first_name'])) {
              $query['conditions']['first_name'] = $form['first_name'];
          }

          if (isset($form['last_name'])) {
              $query['conditions']['last_name'] = $form['last_name'];
          }

          // $query['conditions']['contact_id'] = ''; //OMP-2677

          $posts = $this->Contact->find('count', $query);
          $pPosts['xml']['total'] = $posts;

          $this->set('results', $pPosts);
        }
    }

    /**
     * @api {post} ?object=contact&action=create Create a Contact
     * @apiName Create
     * @apiGroup Contact
     * @apiHeader  {String} Authorization Authorization value. Generally "Bearer ACCESSTOKEN"
     * @apiParam {String} domain       Identifies new Contact's Domain.
     * @apiParam {String} user         Specifies user/extension for the User whose Contact you want.
     * @apiParam {String} first_name   Contact's first name
     * @apiParam {String} last_name    Contact's last name
     * @apiParam {String} [home_phone] Contact's home number
     * @apiParam {String} [cell_phone] Contact's mobile number
     * @apiParam {String} [work_phone] Contact's work number
     * @apiParam {String} [email]      Contact's email address
     * @apiParam {String} [fax]        Fax number
     * @apiPermission User
     * @apiSampleRequest ?format=json&object=contact&action=create
     */
    public function create($form) {
        //if form has the "na" as contact_id then that means it is an extension, put in the tag
        $this->nslog('debug', '('.$this->name.'.create) form '.print_r($form, true));

        if (!isset($form['contact_id']) || $form['contact_id'] == null){
          $this->validateFormHasUid($form);
          $this->validateFormParams($form, 'first_name', 'last_name');
        }
        if (!isset($form['tags'])) {
          $form['tags'] = ''; //initialize tags if not set
        }

        //getting time and hash
        $t=time();
        $time = date("Y-m-d H:i:s",$t);
        //$this->nslog('debug', 'this is time: '.$time);
        if (isset($form['contact_id']) && $form['contact_id'] == 'na') {
          $form['tags'] .= "ext,";
        }
        if (!isset($form['contact_id']) || $form['contact_id'] == 'na' || $form['contact_id'] == '') {
          $toHash = $form['user'].$form['domain'].$form['first_name'].$form['last_name'].$time;
          //$this->nslog('debug', 'this is toHash: '.$toHash);
          $this->nslog('debug', 'FINAL HASH: '.md5($toHash));
          $form['contact_id'] = md5($toHash);
          if (!isset($form['tags']) || $form['tags'] == '') 
            if (isset($form['user']) && $form['user'] == 'domain')
              $form['tags'] = "shared_domain,";
          $form['tags'] = $form['tags'].",post,"; //when made with the new contact_id functionality need to show that it is a "post contact_id"
                                    //because there are still contacts that need to be joined but dont have the "ext", so need
                                    //to differentiate btw the ones that need to be joined (that are pre contact_id) and the ones
                                    //that are post
        }

        // check if contact already exists, if it does, do not create a duplicate
        $contactsQuery['conditions']['domain'] = $form['domain'];
        if (isset($form['first_name'])) {
            $contactsQuery['conditions']['first_name'] = $form['first_name'];
        }
        if (isset($form['last_name'])) {
            $contactsQuery['conditions']['last_name'] = $form['last_name'];
        }
        if (isset($form['middle_name'])) {
            $contactsQuery['conditions']['middle_name'] = $form['middle_name'];
        }
        if (isset($form['user'])) {
            $contactsQuery['conditions']['user'] = $form['user'];
        }
        if (isset($form['home_phone'])) {
            $contactsQuery['conditions']['home_phone'] = $form['home_phone'];
        }
        if (isset($form['cell_phone'])) {
            $contactsQuery['conditions']['cell_phone'] = $form['cell_phone'];
        }
        if (isset($form['work_phone'])) {
            $contactsQuery['conditions']['work_phone'] = $form['work_phone'];
        }
        if (isset($form['email'])) {
            $contactsQuery['conditions']['email'] = $form['email'];
        }
        if (isset($form['fax'])) {
            $contactsQuery['conditions']['fax'] = $form['fax'];
        }
        $contacts = $this->Contact->find('all', $contactsQuery);

        // a contact with these parameters exists already, should not create
        if (isset($contacts[0]['Contact'])) {

            if ($this->isv2()) {
                $this->errorResponse(409, 'Exact contact already exists');
                exit;
            }
            
            $this->nslog('debug', 'CONTACT ALREADY EXISTS, DO NOT MAKE: '.print_r($form, true));
            // update the contact if it already exists but tags are different
            if ($form['tags'] != $contacts[0]['Contact']['tags']) {
                $this->update($form);
            }
        } else {
            // create contact for iotum if the user has iotum enabled
            $subUser = $this->__getSubscriber($form['domain'], $form['user']);
            $this->nslog('debug', 'Subscriber create iotum contact check: '.print_r($subUser, true));

            if (Configure::read('Iotum_Admin.api_token')) {
                if ($form['user'] == 'domain') {
                    // Create a shared contact for iotum
                    $this->__createIotumSharedContact($form);
                } elseif (
                    $subUser &&
                    isset($subUser['xml']['subscriber'][0]['iotum_video_hostid'])
                    && $subUser['xml']['subscriber'][0]['iotum_video_hostid'] != ''
                ) {
                    $this->__createIotumContact(
                        $form,
                        $subUser['xml']['subscriber'][0]['iotum_video_hostid']
                    );
                }
            }

            $this->nslog('debug', 'Creating contact: '.print_r($form, true));

            if (!$this->Contact->nsCreate($form,  'contact_events', 'contact')) {
                $this->errorResponse(400, 'Service bus communication error');
            }
        }
        
        if ($this->isv2()) {
            $this->set('singular', "1");

            if (($this->isV2())) {
                for ($i = 0; $i < 20; $i++) { //up to 2 second wait
                    $query=array();
                    $query['object'] = 'contact';
                    $query['action'] = 'read';
                    $query['contact_id'] = $form['contact_id'];
                    $query['domain'] = $form['domain'];
                    $pPosts = $this->Contact->nsRead($query);
                    if (isset($pPosts['xml']['contact'][0]['contact_id']) && $pPosts['xml']['contact'][0]['contact_id'] == $form['contact_id']) {
                        $this->set('format', "json");
                        $this->set('singular', "1");
                        return $this->read_contact($form);
                    }
                    usleep(100000); //100ms
                }
            } 
        }


        $this->responseWithJson($form); //[API-675] return contact information when create success
    }

    private function __getSubscriber($domain, $user) {
        $subQuery = [
            'user' => $user,
            'domain' => $domain,
            'object' => 'subscriber'
        ];
    
        if (!isset($this->Subscriber)) {
            $this->loadModel('Subscriber');
        }
    
        return $this->Subscriber->nsRead($subQuery);
    }

    /**
     * Creates an iotum contact for a user.
     *
     * Formats user data from the form, sends it to the iotum API to create a contact,
     * and updates the form with the new contact ID if creation is successful.
     *
     * @param array &$form Reference to user data form.
     * @param string $iotumHostId iotum host ID for the contact.
     */
    private function __createIotumContact(&$form, $iotumHostId) {
        $this->nslog('debug', 'Creating iotum contact for user: ' . $form['user'] . ' in domain: ' . $form['domain']);
        $this->nslog('debug', '__createIotumContact Iotum host id: ' . $iotumHostId);

        // get the iotum host from the iotumhostid
        $iotumHost = $this->_getIotumHost($form, $iotumHostId);
        $this->nslog('debug', 'Creating iotum contact Iotum host: ' . print_r($iotumHost, true));

        // format contact for iotum
        $iotumContact['name'] = $form['first_name'] . (isset($form['middle_name']) ? ' ' . $form['middle_name'] : '') . ' ' . $form['last_name'];

        // for email, if the email is set and has a semicolon, split it and use the first email
        if (isset($form['email']) && strpos($form['email'], ';') !== false) {
            $emails = explode(';', $form['email']);
            $iotumContact['email'] = trim($emails[0]);
        } else {
            $iotumContact['email'] = isset($form['email']) ? $form['email'] : '';
        }

        $locale = $iotumHost['language'] ?? $iotumHost['locale'] ?? 'en-US';
        $iotumContact['business_phone'] = $this->formatPhoneNumberWithLocale($form['work_phone'] ?? '', $locale);
        $iotumContact['mobile_phone'] = $this->formatPhoneNumberWithLocale($form['cell_phone'] ?? '', $locale);
        $iotumContact['home_phone'] = $this->formatPhoneNumberWithLocale($form['home_phone'] ?? '', $locale);

        $contacts[] = ($iotumContact);

        $field = [
            "host_id" => $iotumHostId,
            "contacts" => $contacts,
        ];

        $VideoController = new VideoController();
        $VideoController->constructClasses();
        $responseIotumContactCreate = $VideoController->_curlIotum($form, 'contact/create', $field);

        // set the iotum contact id if it was created
        $this->nslog('debug', 'Iotum contact create response: ' . print_r($responseIotumContactCreate, true));
        if (isset($responseIotumContactCreate['0']['contact_id'])) {
            $form['iotum_contact_id'] = $responseIotumContactCreate['0']['contact_id'];
        }
    }

    private function __createIotumSharedContact(&$form) {
        $this->nslog('debug', 'Creating SHARED iotum contact for user: ' . $form['user'] . ' in domain: ' . $form['domain']);
        // format contact for iotum
        $iotumContact['name'] = $form['first_name'] . (isset($form['middle_name']) ? ' ' . $form['middle_name'] : '') . ' ' . $form['last_name'];

        // iotumHostId has to be the company_id for shared contacts
        $domQuery['domain'] = $form['domain'];
        $domQuery['object'] = 'domain';
        if (!isset($this->Domain)) {
            $this->loadModel('Domain');
        }

        $this->nslog('debug', '('.$this->name.'.__createIotumSharedContact) domQuery '.print_r($domQuery, true));
        $domain = $this->Domain->nsRead($domQuery);

        $this->nslog('debug', '('.$this->name.'.__createIotumSharedContact) domain '.print_r($domain, true));

        if (!isset($domain['xml']['domain'][0]['iotum_company_id']) || $domain['xml']['domain'][0]['iotum_company_id'] == '') {
            $this->nslog('debug', 'No iotum company id found for domain: ' . $form['domain']);
            return false;
        }

        $iotumHostId = $domain['xml']['domain'][0]['iotum_company_id'];

        // for email, if the email is set and has a semicolon, split it and use the first email
        if (isset($form['email']) && strpos($form['email'], ';') !== false) {
            $emails = explode(';', $form['email']);
            $iotumContact['email'] = trim($emails[0]);
        } else {
            $iotumContact['email'] = isset($form['email']) ? $form['email'] : '';
        }

        // get locale to format phone numbers, since this is shared, we can use the domain's locale from PORTAL_LOCALIZATION_NUMBER_FORMAT
        if (!isset($this->Uiconfig)) {
            $this->loadModel('Uiconfig');
        }
        $number_format = $this->Uiconfig->Query('PORTAL_LOCALIZATION_NUMBER_FORMAT', 'US', '*', $domain['xml']['domain'][0]['domain'], '*', '*', '*');

        $this->nslog('debug', '('.$this->name.'.__createIotumSharedContact) number_format '.print_r($number_format, true));

        // if the number format is not found, default to US
        $number_format = $number_format ?? 'US';

        // map country code to locale
        $countryToLocale = [
            'US' => 'en-US',
            'GB' => 'en-GB',
            'AU' => 'en-AU',
            'MX' => 'es-MX',
            'CA' => 'en-CA', // Assuming default to English for Canada
            'BR' => 'pt-BR',
            'DE' => 'de-DE',
            'FR' => 'fr-FR',
        ];

        $locale = $countryToLocale[$number_format] ?? 'en-US';

        $iotumContact['business_phone'] = $this->formatPhoneNumberWithLocale($form['work_phone'] ?? '', $locale);
        $iotumContact['mobile_phone'] = $this->formatPhoneNumberWithLocale($form['cell_phone'] ?? '', $locale);
        $iotumContact['home_phone'] = $this->formatPhoneNumberWithLocale($form['home_phone'] ?? '', $locale);

        $contacts[] = ($iotumContact);

        $field = [
            "company_id" => $iotumHostId,
            "contacts" => $contacts,
        ];

        $VideoController = new VideoController();
        $VideoController->constructClasses();
        $responseIotumContactCreate = $VideoController->_curlIotum($form, 'contact/create', $field);

        // set the iotum contact id if it was created
        $this->nslog('debug', 'Iotum contact create response: ' . print_r($responseIotumContactCreate, true));
        if (isset($responseIotumContactCreate['0']['contact_id'])) {
            $form['iotum_contact_id'] = $responseIotumContactCreate['0']['contact_id'];
        }
    }

    private function __updateIotumSharedContact($form, $contact) {
        $this->nslog('debug', 'Updating iotum shared contact for user: ' . $form['user'] . ' in domain: ' . $form['domain']);
        $this->nslog('debug', 'Iotum contact id: ' . $contact['iotum_contact_id']);

        $iotumContactId = $contact['iotum_contact_id'];

        // iotumHostId has to be the company_id for shared contacts
        $domQuery['domain'] = $form['domain'];
        $domQuery['object'] = 'domain';
        if (!isset($this->Domain)) {
            $this->loadModel('Domain');
        }

        $this->nslog('debug', '('.$this->name.'.__updateIotumSharedContact) domQuery '.print_r($domQuery, true));
        $domain = $this->Domain->nsRead($domQuery);

        $this->nslog('debug', '('.$this->name.'.__updateIotumSharedContact) domain '.print_r($domain, true));

        if (!isset($domain['xml']['domain'][0]['iotum_company_id']) || $domain['xml']['domain'][0]['iotum_company_id'] == '') {
            $this->nslog('debug', 'No iotum company id found for domain: ' . $form['domain']);
            return false;
        }

        $iotumHostId = $domain['xml']['domain'][0]['iotum_company_id'];

        $field = [
            "company_id" => $iotumHostId,
            "contact_id" => $iotumContactId,
        ];

        // format contact for iotum
        // choose the $form first_name if it is set, otherwise use the contact first_name
        $first_name = isset($form['first_name']) ? $form['first_name'] : $contact['first_name'];
        $middle_name = isset($form['middle_name']) ? $form['middle_name'] : $contact['middle_name'];
        $last_name = isset($form['last_name']) ? $form['last_name'] : $contact['last_name'];

        $field['name'] = $first_name . ($middle_name ? ' ' . $middle_name : '') . ' ' . $last_name;

        // for email, if the email is set and has a semicolon, split it and use the first email
        if (isset($form['email'])) {
            $emails = explode(';', $form['email']);
            $field['email'] = trim($emails[0]);
        }

        // get locale to format phone numbers
        if (!isset($this->Uiconfig)) {
            $this->loadModel('Uiconfig');
        }
        $number_format = $this->Uiconfig->Query('PORTAL_LOCALIZATION_NUMBER_FORMAT', 'US', '*', $domain['xml']['domain'][0]['domain'], '*', '*', '*');
    
        $this->nslog('debug', 'Number format: ' . print_r($number_format, true));
    
        // if the number format is not found, default to US
        $number_format = $number_format ?? 'US';
    
        // map country code to locale
        $countryToLocale = [
            'US' => 'en-US',
            'GB' => 'en-GB',
            'AU' => 'en-AU',
            'MX' => 'es-MX',
            'CA' => 'en-CA', // Assuming default to English for Canada
            'BR' => 'pt-BR',
            'DE' => 'de-DE',
            'FR' => 'fr-FR',
        ];

        $locale = $countryToLocale[$number_format] ?? 'en-US'; // Default to en-US if mapping not found

        if (isset($form['work_phone'])) {
            $field['business_phone'] = $this->formatPhoneNumberWithLocale($form['work_phone'], $locale);
        }

        if (isset($form['cell_phone'])) {
            $field['mobile_phone'] = $this->formatPhoneNumberWithLocale($form['cell_phone'], $locale);
        }

        if (isset($form['home_phone'])) {
            $field['home_phone'] = $this->formatPhoneNumberWithLocale($form['home_phone'], $locale);
        }

        $VideoController = new VideoController();
        $VideoController->constructClasses();

        $this->nslog('debug', 'Iotum shared contact update field: ' . print_r($field, true));

        $responseIotumContactUpdate = $VideoController->_curlIotum($form, 'contact/update', $field);

        $this->nslog('debug', 'Iotum shared contact update response: ' . print_r($responseIotumContactUpdate, true));
    }

    private function __deleteIotumSharedContact($form, $contact) {
        $this->nslog('debug', 'Deleting SHARED iotum contact for user: ' . $form['user'] . ' in domain: ' . $form['domain']);
        $this->nslog('debug', 'Iotum contact id: ' . $contact['iotum_contact_id']);

        $iotumContactId = $contact['iotum_contact_id'];

        // iotumHostId has to be the company_id for shared contacts
        $domQuery['domain'] = $form['domain'];
        $domQuery['object'] = 'domain';
        if (!isset($this->Domain)) {
            $this->loadModel('Domain');
        }

        $this->nslog('debug', '('.$this->name.'.__deleteIotumSharedContact) domQuery '.print_r($domQuery, true));
        $domain = $this->Domain->nsRead($domQuery);

        $this->nslog('debug', '('.$this->name.'.__deleteIotumSharedContact) domain '.print_r($domain, true));

        if (!isset($domain['xml']['domain'][0]['iotum_company_id']) || $domain['xml']['domain'][0]['iotum_company_id'] == '') {
            $this->nslog('debug', 'No iotum company id found for domain: ' . $form['domain']);
            return false;
        }

        $iotumHostId = $domain['xml']['domain'][0]['iotum_company_id'];


        $field = [
            "company_id" => $iotumHostId, 
            "contact_id" => $iotumContactId,
        ];

        $VideoController = new VideoController();
        $VideoController->constructClasses();

        $this->nslog('debug', 'Iotum shared contact delete field: ' . print_r($field, true));

        $responseIotumContactDelete = $VideoController->_curlIotum($form, 'contact/delete', $field);

        $this->nslog('debug', 'Iotum shared contactdelete response: ' . print_r($responseIotumContactDelete, true));
    }

    /**
     * Updates the iotum contact with the provided form data and contact information.
     *
     * @param array $form The form data containing user and domain information.
     * @param array $contact The contact information to be updated.
     * @param int $hostId The host ID of the contact.
     * @return void
     */
    private function __updateIotumContact($form, $contact, $hostId) {
        $this->nslog('debug', 'Updating iotum contact for user: ' . $form['user'] . ' in domain: ' . $form['domain']);
        $this->nslog('debug', 'Iotum contact id: ' . $contact['iotum_contact_id']);

        $iotumContactId = $contact['iotum_contact_id'];

        // get the iotum host from the hostId
        $iotumHost = $this->_getIotumHost($form, $hostId);

        $this->nslog('debug', '__updateIotumContact Iotum host: ' . print_r($iotumHost, true));

        $field = [
            "host_id" => $hostId,
            "contact_id" => $iotumContactId,
        ];

        // format contact for iotum
        // choose the $form first_name if it is set, otherwise use the contact first_name
        $first_name = isset($form['first_name']) ? $form['first_name'] : $contact['first_name'];
        $middle_name = isset($form['middle_name']) ? $form['middle_name'] : $contact['middle_name'];
        $last_name = isset($form['last_name']) ? $form['last_name'] : $contact['last_name'];

        $field['name'] = $first_name . ($middle_name ? ' ' . $middle_name : '') . ' ' . $last_name;

        // for email, if the email is set and has a semicolon, split it and use the first email
        if (isset($form['email'])) {
            $emails = explode(';', $form['email']);
            $field['email'] = trim($emails[0]);
        }

        $locale = $iotumHost['language'] ?? $iotumHost['locale'] ?? 'en-US';

        if (isset($form['work_phone'])) {
            $field['business_phone'] = $this->formatPhoneNumberWithLocale($form['work_phone'], $locale);
        }

        if (isset($form['cell_phone'])) {
            $field['mobile_phone'] = $this->formatPhoneNumberWithLocale($form['cell_phone'], $locale);
        }

        if (isset($form['home_phone'])) {
            $field['home_phone'] = $this->formatPhoneNumberWithLocale($form['home_phone'], $locale);
        }

        $VideoController = new VideoController();
        $VideoController->constructClasses();

        $this->nslog('debug', 'Iotum contact update field: ' . print_r($field, true));

        $responseIotumContactUpdate = $VideoController->_curlIotum($form, 'contact/update', $field);

        $this->nslog('debug', 'Iotum contact update response: ' . print_r($responseIotumContactUpdate, true));
    }

    /**
     * Deletes an iotum contact for a user in a specific domain.
     *
     * @param array $form The form data containing user and domain information.
     * @param array $contact The contact information to be deleted.
     * @param int $hostId The host ID of the contact.
     * @return void
     */
    private function __deleteIotumContact($form, $contact, $hostId) {
        $this->nslog('debug', 'Deleting iotum contact for user: ' . $form['user'] . ' in domain: ' . $form['domain']);
        $this->nslog('debug', 'Iotum contact id: ' . $contact['iotum_contact_id']);

        $iotumContactId = $contact['iotum_contact_id'];

        $field = [
            "host_id" => $hostId,
            "contact_id" => $iotumContactId,
        ];

        $VideoController = new VideoController();
        $VideoController->constructClasses();

        $this->nslog('debug', 'Iotum contact delete field: ' . print_r($field, true));

        $responseIotumContactDelete = $VideoController->_curlIotum($form, 'contact/delete', $field);

        $this->nslog('debug', 'Iotum contact delete response: ' . print_r($responseIotumContactDelete, true));
    }

    /**
     * @api {post} ?object=contact&action=update Update Contacts
     * @apiName Update
     * @apiGroup Contact
     * @apiHeader {String} Authorization Authorization value. Generally "Bearer ACCESSTOKEN".
     * @apiParam {String} domain        Identifies Contacts to update by Domain.
     * @apiParam {String} user          Identifies Contacts to update by User/Extension.
     * @apiParam {String} first_name    Identifies Contacts to update by first name.
     * @apiParam {String} last_name     Identifies Contacts to update by last name.
     * @apiParam {String} [company]     New value for Contact.
     * @apiParam {String} [work_phone]  New value for Contact.
     * @apiParam {String} [cell_phone]  New value for Contact.
     * @apiParam {String} [fax] New value for Contact.
     * @apiParam {String} [email]       New value for Contact.
     * @apiParam {String} [home_phone]  New value for Contact.
     * @apiParam {String} [time_answer] New value for Contact.
     * @apiParam {String} [tags]        New value for Contact.
     * @apiParam {String} [ts]          New value for Contact.
     * @apiParam {String} [contact_id]          Contact_id hash for contact, should not change, it is the index.
     * @apiPermission Reseller
     * @apiSampleRequest ?format=json&object=contact&action=update
     */
    public function update($form) {
        $this->nslog('debug', '('.$this->name.'.update) form '.print_r($form, true));

        /* Set primary key for find operation. */
        if (!isset($form['contact_id'])) { // if no contact_id, we need all other params
            $this->validateFormHasUid($form);
            $this->validateFormParams($form, 'first_name', 'last_name');
        }
        //suggestion was to unset the user parameter for all updates, this was because
        //in updating a shared contact, the user would be given and thus would overwrite
        //the existing user/shared setting
        //but should only do this if the user is 'domain' or later on some other form of shared deliminator
        if (isset($form['tags']) && strpos($form['tags'], 'shared') !== false) { // i need a way to read shared contacts and if it is a shared contact to unset user
          unset($form['user']);
          $form['user'] = 'domain';
        } else {
        }

        //backwards compatibility
        if (!isset($form['contact_id']) || $form['contact_id'] == '') {
          $query['conditions']['domain'] = $form['domain'];
          $query['conditions']['user'] = $form['user'];

          if (isset($form['first_name'])) {
              $query['conditions']['first_name'] = $form['first_name'];
          }

          if (isset($form['last_name'])) {
              $query['conditions']['last_name'] = $form['last_name'];
          }

          if (isset($form['tags'])) {
              $query['conditions']['tags LIKE'] = '%'.$form['tags'].'%';
          }
          //read all the contacts and see which one has valid contact_id's and change those
          $posts = $this->Contact->find('all', $query);

          foreach ($posts as $key => $value) {
            if (($posts[$key]['Contact']['contact_id'] != '') && ($posts[$key]['Contact']['contact_id'] != NULL)) {
              //contact_id was not blank, has a valid contact_id
              $form['contact_id'] = $posts[$key]['Contact']['contact_id'];
              if (!$this->Contact->nsUpdate($form,  'contact_events', 'contact')) {
                $this->errorResponse(400, 'Service bus communication error');
              }
              else {
                $this->nullResponse(HTTP_STATUS_CODE_UPDATE_SUCCESS);
              }

            }
          }
          $t=time();
          $time = date("Y-m-d H:i:s",$t);
          //fall through because the contact is not available. lets create.
          $toHash = $form['user'].$form['domain'].$form['first_name'].$form['last_name'].$time;
          $this->nslog('debug', 'FINAL HASH: '.md5($toHash));
          $form['contact_id'] = md5($toHash);


          if (!$this->Contact->nsCreate($form,  'contact_events', 'contact')) {
              $this->errorResponse(400, 'Service bus communication error');
          }
          $this->nullResponse(HTTP_STATUS_CODE_UPDATE_SUCCESS);
        }

        //else will just use the contact_id that was given
        if (!$this->Contact->nsUpdate($form,  'contact_events', 'contact')) {
            $this->errorResponse(400, 'Service bus communication error');
        }

        // read the contact to get the iotum contact id, if it is set then update the contact on iotum side, if it does not, then create the contact on iotum side

        // read subscriber first and see if iotum host id is set
        $subUser = $this->__getSubscriber($form['domain'], $form['user']);

        $this->nslog('debug', '('.$this->name.'.update) read for Iotum subUser '.print_r($subUser, true));
        if ($subUser
            && isset($subUser['xml']['subscriber'][0]['iotum_video_hostid'])
            && $subUser['xml']['subscriber'][0]['iotum_video_hostid'] != ''
            && $form['user'] != 'domain'
        ) {
            // read contact to see if iotum contact id is set, if it is update the iotum contact. if not create the iotum contact
            // read the contact first
            $query=array();
            $query['object'] = 'contact';
            $query['action'] = 'read';
            $query['contact_id'] = $form['contact_id'];

            $pPosts = $this->Contact->nsRead($query);

            $this->nslog('debug', '('.$this->name.'.update) read for Iotum pPosts '.print_r($pPosts, true));

            if (Configure::read('Iotum_Admin.api_token')) {
                if (isset($pPosts['xml']['contact'][0]['iotum_contact_id'])) {
                    $this->__updateIotumContact($form, $pPosts['xml']['contact'][0], $subUser['xml']['subscriber'][0]['iotum_video_hostid']);
                } else {
                    $this->__createIotumContact($form, $subUser['xml']['subscriber'][0]['iotum_video_hostid']);
                }    
            }
        }
        if ($form['user'] == 'domain') {
            // read contact to see if iotum contact id is set, if it is update the iotum contact. if not create the iotum contact
            // read the contact first
            $query=array();
            $query['object'] = 'contact';
            $query['action'] = 'read';
            $query['contact_id'] = $form['contact_id'];

            $pPosts = $this->Contact->nsRead($query);

            $this->nslog('debug', '('.$this->name.'.update) read for Iotum pPosts '.print_r($pPosts, true));

            if (Configure::read('Iotum_Admin.api_token')) {
                if (isset($pPosts['xml']['contact'][0]['iotum_contact_id'])) {
                    $this->__updateIotumSharedContact($form, $pPosts['xml']['contact'][0]);
                } else {
                    $this->__createIotumSharedContact($form);
                }    
            }
        }

        $this->nullResponse(HTTP_STATUS_CODE_UPDATE_SUCCESS);
    }

    /**
     * @api {post} ?object=contact&action=delete Delete a Contact
     * @apiName Delete
     * @apiGroup Contact
     * @apiHeader  {String} Authorization Authorization value. Generally "Bearer ACCESSTOKEN"
     * @apiParam {String} domain       Identifies Contact to delete by Domain.
     * @apiParam {String} user         Specifies user/extension for the User whose Contact you want.
     * @apiParam {String} first_name   Contact's first name.
     * @apiParam {String} last_name    Contact's last name.
     * @apiParam {String} contact_id    Hash that is contact's id.
     * @apiPermission User
     * @apiSampleRequest ?format=json&object=contact&action=delete
     */
    public function delete($form)
    {
        $this->nslog('debug', '('.$this->name.'.delete) form '.print_r($form, true));

        if ($form['object'] == 'contacts') {
            $this->bulkDelete($form);
            exit;
        }

        /* Set primary key for find operation. */
        if (!isset($form['contact_id'])) { // if no contact_id, we need all other params
            $this->validateFormHasUid($form);
            $this->validateFormParams($form, 'first_name', 'last_name');
        }

        //backwards compatibility
        if (!isset($form['contact_id']) || $form['contact_id'] == '') {
            $query['conditions']['domain'] = $form['domain'];
            $query['conditions']['user'] = $form['user'];

            if (isset($form['first_name'])) {
                $query['conditions']['first_name'] = $form['first_name'];
            }

            if (isset($form['last_name'])) {
                $query['conditions']['last_name'] = $form['last_name'];
            }

            if (isset($form['tags'])) {
                $query['conditions']['tags LIKE'] = '%'.$form['tags'].'%';
            }
            //read all the contacts and see which one has valid contact_id's and change those
            $posts = $this->Contact->find('all', $query);
            foreach ($posts as $key => $value) {
                if (($posts[$key]['Contact']['contact_id'] != '') && ($posts[$key]['Contact']['contact_id'] != NULL)) {
                    //contact_id was not blank, has a valid contact_id
                    $form['contact_id'] = $posts[$key]['Contact']['contact_id'];
                    if (!$this->Contact->nsDelete($form,  'contact_events', 'contact')) {
                        $this->errorResponse(400, 'Service bus communication error');
                    }
                }
            }

            $this->nullResponse(HTTP_STATUS_CODE_DELETE_SUCCESS);
        } else {
            //else use the contact_id for deleting


            $contactsQuery['conditions']['contact_id'] = $form['contact_id'];
            // if we are looking up by contact_id, do not need to use user/domain anymore
            // if (isset($form['user']) && $form['user'] != "*") {
            //     $contactsQuery['conditions']['user'] = $form['user'];
            // }
            // if (isset($form['domain']) &&  $form['domain'] != "*") {
            //     $contactsQuery['conditions']['domain'] = $form['domain'];
            // }
            $contacts = $this->Contact->find('all', $contactsQuery);

            $this->nslog('debug', '('.$this->name.'.delete) contacts '.print_r($contacts, true));
            $this->nslog('debug', '('.$this->name.'.delete) contactsQuery '.print_r($contactsQuery, true));

            if ($this->isv2() && !isset($contacts[0]['Contact'])) {
                $this->errorResponse(409, 'contact doesn\'t exist');
                exit;
            }

            // save the domain for later use
            $domain = $form['domain'];

            unset($form['domain']); //else domain will be * and will not delete anything

            if (!$this->Contact->nsDelete($form,  'contact_events', 'contact')) {
                $this->errorResponse(400, 'Service bus communication error');
            }

            // check if iotum contact_id is set and also if user has iotum host id set, if so, then delete on iotum side
            if (isset($contacts[0]['Contact']['iotum_contact_id'])) {
                $form['domain'] = $domain;
                $subUser = $this->__getSubscriber($form['domain'], $form['user']);

                if (Configure::read('Iotum_Admin.api_token')
                    && $subUser
                    && isset($subUser['xml']['subscriber'][0]['iotum_video_hostid'])
                    && $subUser['xml']['subscriber'][0]['iotum_video_hostid'] != ''
                    && $contacts[0]['Contact']['user'] != 'domain'
                ) {
                    $this->__deleteIotumContact($form, $contacts[0]['Contact'], $subUser['xml']['subscriber'][0]['iotum_video_hostid']);
                } else if (
                    Configure::read('Iotum_Admin.api_token')
                    && $contacts[0]['Contact']['user'] == 'domain'
                    && isset($contacts[0]['Contact']['iotum_contact_id'])
                    && $contacts[0]['Contact']['iotum_contact_id'] != ''
                ) {
                    $this->__deleteIotumSharedContact($form, $contacts[0]['Contact']);
                }
            }

            $this->nullResponse(HTTP_STATUS_CODE_DELETE_SUCCESS);
        }
    }

    private function bulkDelete($form)
    {
        $this->nslog('debug', '('.$this->name.'.bulkDelete) form '.print_r($form, true));

        $this->validateFormHasUid($form);

        if (!$this->Contact->nsDelete($form,  'contact_events', 'contacts')) {
            $this->nslog('debug', '('.$this->name.'.bulkDelete) nsDelete failed');
            $this->errorResponse(400, 'Service bus communication error');
        }

        $this->nullResponse(HTTP_STATUS_CODE_DELETE_SUCCESS);
    }

    /**
     * Formats a phone number according to the specified locale.
     *
     * As of Sept 4, 2024, the only languages supported are:
     * - English (US)
     * - French (France)
     * - German (Germany)
     * - Spanish (Spain)
     *
     * This function will prepend the appropriate country code to the phone number
     * if it's not already present, based on the provided locale.
     *
     * @param string $phoneNumber The phone number to format
     * @param string $locale The locale code (e.g., 'en-US', 'fr-FR')
     * @return string The formatted phone number
     */
    private function formatPhoneNumberWithLocale($phoneNumber, $locale) {
        // as of Sept 4, 2024 the only langauge supported are 
        // English (US)
        // French (France)
        // German (Germany)
        // Spanish (Spain)

        $localePrefixes = [
            'en-us' => '1',   // United States
            'en-gb' => '44',  // United Kingdom
            'en-au' => '61',  // Australia
            'es-mx' => '52',  // Mexico
            'fr-ca' => '1',   // Canada (French)
            'pt-br' => '55',  // Brazil
            'es-es' => '34',  // Spain
            'fr-fr' => '33',  // France
            'de-de' => '49',  // Germany
        ];
    
        if (empty($phoneNumber)) return '';
    
        // Remove non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    
        // Normalize locale code
        $locale = str_replace('_', '-', strtolower($locale));
    
        // Check if the locale prefix is already present
        if (isset($localePrefixes[$locale]) && !preg_match('/^' . $localePrefixes[$locale] . '/', $phoneNumber)) {
            return $localePrefixes[$locale] . $phoneNumber;
        }
    
        return $phoneNumber;
    }

    /**
     * Retrieves the Iotum host information for a given host ID.
     *
     * This function first checks the cache for the host information. If not found,
     * it makes an API call to fetch the host details and then caches the result.
     *
     * @param array $form The form data containing necessary information for the API call
     * @param string|int $hostId The ID of the Iotum host to retrieve
     * @return array The Iotum host information
     */
    private function _getIotumHost($form, $hostId) {
        $this->nslog('debug', '('.$this->name.'._getIotumHost) hostId '.print_r($hostId, true));

        App::import('Controller', 'Video');
        $VideoController = new VideoController();

        $field = [
            "host_id" => $hostId
        ];

        // check cache for iotum host first before querying
        $iotumHostCache = Cache::read('cache'.'_iotum_host_'.$hostId, '_cname_');

        if ($iotumHostCache != null && $iotumHostCache !== false) {
            $this->nslog('debug', '('.$this->name.'._getIotumHost) iotum host was found in cache: '.print_r($iotumHostCache, true));
            return $iotumHostCache;
        }

        $response = $VideoController->_curlIotum($form, 'host/fetch', $field);

        // store iotum host in cache
        if (isset($response['host_id']) && $response['host_id'] == $hostId) {
            Cache::write('cache'.'_iotum_host_'.$hostId, $response, '_cname_');
        }

        $this->nslog('debug', '('.$this->name.'._getIotumHost) response '.print_r($response, true));

        return $response;
    }

    public function eventCreate($event)
    {
        $this->nslog('debug', '('.$this->name.'.eventCreate) event '.print_r($event, true));

        $this->Contact->nsCreateFromSbus($event);
    }

    public function eventUpdate($event)
    {
        $this->nslog('debug', '('.$this->name.'.eventUpdate) event '.print_r($event, true));

        $this->Contact->nsUpdateFromSbus($event);
    }

    public function eventDelete($event)
    {
        $this->nslog('debug', '('.$this->name.'.eventDelete) event '.print_r($event, true));
        if ($event['object'] == 'contacts' && (isset($event['contact_id']) && $event['contact_id'] != ""))
          $this->Contact->nsDeleteFromSbus($event, array('first_name' => '', 'last_name' => '', 'user' => '', 'domain' => ''));
        else if ($event['object'] == 'contacts' && !isset($event['contact_id']))
          $this->Contact->nsDeleteFromSbus($event, array('first_name' => '', 'last_name' => '', 'contact_id' => ''), array('user' => $event['user'], 'domain' => $event['domain']));
        else
          $this->Contact->nsDeleteFromSbus($event);
    }
}
