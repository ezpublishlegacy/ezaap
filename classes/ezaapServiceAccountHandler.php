<?php

/**
 *
 * Usage :
 *
 * $service = ezaapService::get( 'Account' );
 * $credentials = array( '_username' => 'b2c1@france.fr',
                         '_password' => 'sensio' );
 * $service->authenticate( $credentials );
 *
 * Availables public methods
 * $service->isLoggedIn();
 * $service->getUserData();
 *
 * @property ezaapServiceMethod $Authenticate
 * @property ezaapServiceMethod $BusinessSelect
 * @property ezaapServiceMethod $BusinessList
 *
 *
 */
class ezaapServiceAccountHandler extends ezaapService
{
    /**
     *
     * @todo à mettre dans fichier de config
     * @var string
     */
    const REDIRECT_AFTER_LOGIN_CHECK = '/auth/ecom/dump.json';
    const SERVICE_NAME = 'Account';

    /**
     *
     * @var Buzz\Cookie\Cookie
     */
    private $token;

    private $logged = false;

    /**
     *
     * For security reason
     * This service can not be called using /ezaap/service/Account/Authenticate
     *
     * @todo being able to define this property for each service methods
     *
     * @return type
     */
    public function availableThroughServiceModule()
    {
        return true;
    }

    /**
     *
     * Adds the needed inputs to the post request
     * Also initialise the session through a first fake call to the service
     *
     * @todo add validation on username and password
     *
     */
    public function preAuthenticateRequest()
    {
        $username = $this->requestArguments['_username'];
        $password = $this->requestArguments['_password'];

        // première initialisation de la session
        $this->request->setMethod(Buzz\Message\Request::METHOD_GET);
        $this->request->setResource( $this->configuration->getParameter('URI', $this->currentMethod) );
        $this->request->setHost($this->configuration->Server);

        // save host and resource
        $host = $this->request->getHost();
        $resource = $this->request->getResource();


        // empeche la redirection pour permettre d'y rajouter un cookie
        $this->client->setMaxRedirects(0);
        $this->client->send($this->request, $this->response);

        // retrieve Set-Cookie
        $cookieReturned = new Buzz\Cookie\Cookie();
        $cookieReturned->fromSetCookieHeader( $this->response->getHeader('Set-Cookie') );

        // requete d'authentification
        $this->request = new Buzz\Message\FormRequest();

        // re-add the token previously return by the first request
        //$this->request->addHeader($this->getCookieToken()->toCookieHeader());
        $this->setTokenToUse( $cookieReturned->getValue() );

        // re-set host and resource
        $this->request->setResource( $resource );
        $this->request->setHost( $host );

        $this->request->setField( '_username', $username );
        $this->request->setField( '_password', $password );
        $this->request->setField( '_target_path', self::REDIRECT_AFTER_LOGIN_CHECK );

        $this->response = new Buzz\Message\Response();
    }

    /**
     *
     * Gère la réponse après authentification
     *
     * @todo gérer les autres codes de retour
     */
    public function postAuthenticateResponse()
    {
        if( $this->getResponseCode() == 302 )
        {
            // if redirected to the requested URI (/auth/ecom/dump.json)
            // means that we are logged
            if (preg_match('/'.preg_quote( self::REDIRECT_AFTER_LOGIN_CHECK , '/').'$/', $this->response->getHeader('location')))
            {
                $this->logged = true;

                // previous request token
                $cookieToken = new Buzz\Cookie\Cookie();
                $cookieToken->setName('_token');
                $cookieToken->setValue( $this->getTokenToUse() );

                // now we can follow the redirection
                $this->request = new Buzz\Message\Request( Buzz\Message\Request::METHOD_GET );
                $this->request->fromUrl($this->response->getHeader('location'));
                $this->request->addHeader($cookieToken->toCookieHeader());

                $this->response = new Buzz\Message\Response();

                $this->client->send($this->request, $this->response);
            }
        }
    }

    protected function preBusinessSelectRequest()
    {
        if( $this->requestArguments['post_request'] )
        {
            $this->request->addFields( $_POST );
        }
        $this->setRedirectURIAfterExecution( $this->requestArguments['referer'] );
    }

    protected function postBusinessSelectResponse()
    {
        $ezaapuser = ezaapUser::instance();
        $ezaapuser->business = $this->getJSONResponse()->business;
        $ezaapuser->roles = $this->getJSONResponse()->roles;
        $ezaapuser->store();

        // clone the current user based on Symfony data
        $newUser = ezaapConnectUser::createWithSFData( $this->getJSONResponse() );
        if( $newUser instanceof eZUser )
        {
            // log a new user in eZ Publish
            $newUser->loginCurrent();
        }
        else
        {
            // @todo handle such error
            eZLog::write( "Cannot login user in eZ Publish with roles " . implode( "-", $this->getJSONResponse()->roles ), "ezaap_security.log" );
        }
    }


    protected function postBusinessListResponse()
    {
        // If GET method, means that we are asking which businesses a user has
        // access to
        if( $this->request->getMethod() == Buzz\Message\Request::METHOD_GET )
        {
            $businessList = array();
            $businessList[0]['label'] = ezpI18n::tr( 'account/box', "-" );
            // Replace BusinessList by BusinessSelect
            $formURL = str_replace( $this->currentMethod, 'BusinessSelect', $this->getCurrentURI() );
            foreach( $this->getJSONResponse() as $business )
            {
                $businessList[$business->id] = array( 'label' => $business->name );
            }
            $selectedBusinessID = ezaapUser::instance()->selectedBusiness();
            $selectedBusinessName = $businessList[$selectedBusinessID]['label'];
            $this->responseContent = array( 'business_list' => $businessList,
                                            'form_url' => $formURL,
                                            'selected_business' => $selectedBusinessID,
                                            'selected_business_name' => $selectedBusinessName );
        }
    }

    /**
     *
     * Return the token as a Buzz\Cookie\Cookie instance
     * Contructed used the first response
     *
     * @todo cleanup
     *
     * @return Buzz\Cookie\Cookie
     */
    public function getCookieToken()
    {
        if(!$this->token)
        {
            $this->token = new Buzz\Cookie\Cookie();
            $this->token->fromSetCookieHeader($this->response->getHeader('Set-Cookie'), $this->request->getHost() );
        }

        return $this->token;
    }

    /**
     *
     * Return true if successfully logged in
     *
     * @return bool
     */
    public function isLoggedIn()
    {
        return $this->logged;
    }

    /**
     *
     * Returns the token string
     *
     * @return string
     */
    public function getToken()
    {
        return $this->getCookieToken()->getValue();
    }

    public function getUserData()
    {
        return $this->isLoggedIn() ? json_decode( $this->response->getContent() ) : false;
    }


}
