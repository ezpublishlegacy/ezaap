<?php

/**
 * This class is meant to be extended in order to define each service you want
 * to invoke from eZ Publish
 *
 */
abstract class ezaapService
{
    const COOKIE_TOKEN_NAME = '_token';
    const ROUTE_PREFIX_GET_PARAMETER = 'route_prefix';
    const DEFAULT_TIMEOUT = 10;
    const LOG_FILE = "ezaap";

    /**
     *
     * The name of the current service
     *
     * @var string
     */
    private $serviceName;

    /**
     *
     * The Buzz response instance
     *
     * @var Buzz\Message\Response
     */
    protected $response;

    /**
     *
     * The buzz request instance (can be Request or FormRequest)
     *
     * @var Buzz\Message\Request
     */
    protected $request;

    /**
     *
     * Arguments to be used by the triggers
     *
     * @var array
     */
    protected $requestArguments;


    /**
     *
     * The response which will be made available outside this class (using
     * getResponseContent())
     *
     * @var string
     */
    protected $responseContent;

    /**
     *
     * Variable used to add a token
     *
     * @var string
     */
    private $tokenToUse;

    /**
     *
     * Variable used to add a route prefix
     *
     * @var string
     */
    private $routePrefix;

    /**
     *
     * Variable used to redirect the user after the service execution
     *
     * Possible values :
     * - null means that we redirect to the previous page (default behavior)
     * - false means that we do nothing
     * - a string means that we redirect to that uri
     *
     *
     * @var mixed
     */
    private $redirectURIAfterExecution;

    /**
     *
     * @var Buzz\Client\Curl
     */
    protected $client;

    /**
     *
     * The name of the current method
     *
     * @var string
     */
    protected $currentMethod;

    /**
     *
     * @var ezaapServiceConfiguration
     */
    protected $configuration;

    private $referer;

    /**
     *
     * @param string $serviceName
     * @param boolean $useCurrentUserToken
     */
    public function __construct( $serviceName, $useCurrentUserToken = false )
    {
        $this->serviceName = $serviceName;
        $this->configuration = new ezaapServiceConfiguration( $this );

        if( $useCurrentUserToken )
        {
            $this->setTokenToUse( ezaapUser::getFromSessionObject()->token );
        }

        $this->client = new Buzz\Client\Curl();
        $this->client->setTimeout( self::DEFAULT_TIMEOUT );
    }

    private function availableMethods()
    {
        return $this->configuration->AvailableMethods;
    }

    /**
     * A reimpl pour indiquer si le service symfony peut etre appelé par le
     * proxy eZ Publish /ezaap/service/<servicename>/<method>
     *
     * Doit retourner un booleen
     */
    abstract public function availableThroughServiceModule();

    /**
     *
     * Gère l'appel à un service/method
     *
     * @param string $method
     * @param mixed $arguments
     */
    public function __call( $method, $arguments = array() )
    {
        $this->requestArguments = $arguments[0];
        $this->currentMethod = $method;

        try
        {
            // Check if the method is defined in the configuration file
            if( array_search( $method, $this->availableMethods()) === false )
            {
                throw new Exception( "Method {$method} non existante dans " . get_called_class() );
            }

            // est-ce que l'URI a appelé est configuré
            if( $this->configuration->isSetForMethod( 'URI', $method ) )
            {
                $uri = $this->configuration->getParameter( 'URI', $method );
            }
            else
            {
                $uri = '/';
            }

            $server = $this->configuration->Server;

            $this->response = new Buzz\Message\Response();
            // reinit responseContent in case that we call several methods on
            // a single service
            $this->responseContent = null;

            if( $this->configuration->isSetForMethod( 'RequestTypes', $method ) )
                $requestType = $this->configuration->getParameter( 'RequestTypes', $method );
            else
                $requestType = null;

            // predefines $this->request using configuration settings if possible
            switch( $requestType )
            {
                case 'ajax':
                    // todo enrichissement du post pour l'ajax
                case 'post':
                    $this->request = new Buzz\Message\FormRequest();
                    break;
                case 'get':
                default:
                    $this->request = new Buzz\Message\Request();
                    break;
            }
            $this->request->setHost( $server );
            $this->request->setResource( $uri );

            // triggers request related stuff, implemented at handler level
            eZDebug::writeDebug( $this->request, __CLASS__ . ":{$this->currentMethod}:Request:raw" );
            $this->populateRequest();

            // sends the request
            eZDebug::writeDebug( $this->request, __CLASS__ . ":{$this->currentMethod}:Request:prepared" );
            eZDebug::accumulatorStart( 'request_sending', __CLASS__, 'Request made to the backend' );
            $this->client->send( $this->request, $this->response );
            eZDebug::accumulatorStop( 'request_sending' );
            eZDebug::writeDebug( $this->response, __CLASS__ . ":{$this->currentMethod}:Response:raw" );

            // triggers response related stuff, implemented at handler level
            $this->handleResponse();
            eZDebug::writeDebug( $this->response, __CLASS__ . ":{$this->currentMethod}:Response:handled" );
            $this->log();
        }
        catch( Exception $e )
        {
            // ici on ne gère que le code d'erreur timeout pour curl
            // les codes erreurs pour les autres clients seront
            // vraisemblablement différents
            if( $e->getCode() == CURLE_OPERATION_TIMEOUTED )
            {
                $this->responseContent = "Timeout";
            }
            else
            {
                eZDebug::writeDebug( "Catch exception : {$e->getMessage()}", __CLASS__ );
            }
        }
    }

    /**
     *
     * Convenience method to know if debug is enabled or not
     *
     * Currently calls eZDebug::isDebugEnabled();
     *
     * @return bool
     */
    private function isDebugEnabled()
    {
        return eZDebug::isDebugEnabled();
    }

    protected function getCurrentURI()
    {
        return "/ezaap/service/" . $this->serviceName . "/" . $this->currentMethod;
    }

    private function populateRequest()
    {
        eZDebug::accumulatorStart( 'request_generation', __CLASS__, 'Request generation' );

        $methodNameSuffix = ucfirst( $this->currentMethod ) . "Request";
        $preMethodName = "pre{$methodNameSuffix}";
        $postMethodName = "post{$methodNameSuffix}";

        // pre 'request' trigger
        if( method_exists( $this, $preMethodName ) )
        {
            $this->$preMethodName();
        }

        // traitements génériques effectués sur toutes les requetes
        // add specific client header telling the backend that we are eZ Publish
        $this->addUserAgentToRequest();
        // ajoute le prefix si $this->routePrefix n'est pas null
        $this->addRoutePrefixToRequest();
        // Add the locale to the request
        $this->addLocaleToRequest();

        // Always add token depending on configuration
        if( $this->configuration->AlwaysAddToken == 'true' && !$this->tokenToUse )
        {
            $this->setTokenToUse( ezaapUser::instance()->token );
        }

        // Add cookies stored in the User Session
        $userCookies = ezaapUser::instance()->cookies;
        if( !empty ( $userCookies ))
        {
            $cookiesHeader = array();
            foreach( $userCookies as $name => $value )
            {
                $cookiesHeader[$name] = "$name=$value";
            }
            $cookieString = "Cookie: " . implode( "; ", $cookiesHeader );
            $this->request->addHeader( $cookieString );
        }

        // Add the token cookie to the headers if $this->tokenToUse not null
        // AND if not already set because of a session cookie
        if( !array_key_exists( '_token' , $userCookies ) )
        {
            $this->addTokenToRequest();
        }


        // post 'request' trigger
        if( method_exists( $this, $postMethodName ) )
        {
            $this->$postMethodName();
        }
        eZDebug::accumulatorStop('request_generation');
    }

    private function handleResponse()
    {
        eZDebug::accumulatorStart( 'response_handling', __CLASS__, 'Response handling' );

        $methodNameSuffix = ucfirst( $this->currentMethod ) . "Response";
        $preMethodName = "pre{$methodNameSuffix}";
        $postMethodName = "post{$methodNameSuffix}";

        // 'pre' response trigger
        if( method_exists( $this, $preMethodName ) )
        {
            $this->$preMethodName();
        }

        // traitements génériques

        // if not 200 or 302 and debug enabled => returns response to eZ Publish
        $acceptableResponseCode = array( 200, 302 );
        if( !in_array( $this->getResponseCode(), $acceptableResponseCode) && $this->isDebugEnabled() )
        {
            $this->responseContent = $this->getResponseContent();
        }

        // deal with response containing set-cookie header
        // store it in the eZ user session
        if( $this->response->getHeader( 'Set-Cookie' ))
        {
            $cookie = new \Buzz\Cookie\Cookie();
            $cookie->fromSetCookieHeader($this->response->getHeader( 'Set-Cookie' ) );
            ezaapUser::instance()->setCookie( $cookie->getName(), $cookie->getValue() );
        }

        // 'post' response trigger
        if( method_exists( $this, $postMethodName ) )
        {
            $this->$postMethodName();
        }
        eZDebug::accumulatorStop('response_handling');
    }

    /**
     *
     * Get the response retrieved by buzz
     *
     * @return type
     */
    public function getResponseContent()
    {
        return $this->responseContent;
    }

    /**
     *
     * @param string $serviceName
     * @param boolean $useCurrentToken
     * @return ezaapService
     */
    public static function get( $serviceName, $useCurrentToken = false )
    {
        // @todo lazy loading with static stuff
        return self::loadService( $serviceName, $useCurrentToken );

    }

    public function getServiceName()
    {
        return $this->serviceName;
    }

    /**
     * Returns tthe service handler for the given $serviceName
     *
     * @param string $serviceName
     * @return ezaapService
     */
    private static function loadService( $serviceName, $useCurrentToken = false )
    {
        if( array_search( $serviceName, eZINI::instance( ezaapServiceConfiguration::CONFIG_FILE )->variable('GeneralSettings', 'AvailableServices') ) === false )
        {
            eZDebug::writeDebug( "Unknown service $serviceName", __CLASS__ . ":" . __FUNCTION__ );
            return null;
        }
        // for futur usage
        $handlerParams = array( $serviceName, $useCurrentToken );

        // get the handler using ezp api
        $iniBlockName = "{$serviceName}Settings";
        $optionArray = array( 'iniFile'      => ezaapServiceConfiguration::CONFIG_FILE,
                              'iniSection'   => $iniBlockName,
                              'iniVariable'  => 'Handler',
                              'handlerParams'=> $handlerParams );

        $options = new ezpExtensionOptions( $optionArray );

        return eZExtension::getHandlerClass( $options );
    }

    protected function getResponseCode()
    {
        return $this->response->getStatusCode();
    }

    public function getJSONResponse()
    {
        // todo add test on the result format
        return json_decode( $this->response->getContent() );
    }

    protected function addLocaleToRequest()
    {
        $this->request->addHeader( "eZ-Locale: " . substr( eZLocale::currentLocaleCode(), 0, 2 ) );
    }

    protected function setTokenToUse( $token )
    {
        $this->tokenToUse = $token;
    }

    protected function getTokenToUse()
    {
        return $this->tokenToUse;
    }

    /**
     * Add a header so that the backend can handle the request differently
     */
    protected function addUserAgentToRequest()
    {
        $this->request->addHeader( "User-Agent: eZPublish/eZaaP" );
    }

    private function addTokenToRequest()
    {
        if( !is_null( $this->tokenToUse ))
        {
            $cookie = new \Buzz\Cookie\Cookie();
            $cookie->setName( self::COOKIE_TOKEN_NAME );
            $cookie->setValue( $this->tokenToUse );
            $this->request->addHeader( $cookie->toCookieHeader() );
        }
    }

    /**
     *
     * Set the $uri which will be used to redirect the user when the service
     * execution is done
     *
     *
     * @param mixed $uri
     */
    public function setRedirectURIAfterExecution( $uri )
    {
        // todo validation and warning if the uri can not be handle by eZ
        $this->redirectURIAfterExecution = $uri;
    }

    /**
     * @return mixed
     */
    public function getRedirectURIAfterExecution()
    {
        return $this->redirectURIAfterExecution;
    }

    public function hasToBeRedirected()
    {
        return !is_null( $this->redirectURIAfterExecution );
    }

    public function setRoutePrefix( $prefix )
    {
        $this->routePrefix = $prefix;
    }

    /**
     * Adds the route prefix used by the backend to generate urls
     * Set in GET parameters and headers (not used by the backend)
     */
    protected function addRoutePrefixToRequest()
    {
        if( !is_null($this->routePrefix) )
        {
            $this->addGetParameter( self::ROUTE_PREFIX_GET_PARAMETER, $this->routePrefix );
            $this->request->addHeader( "eZ-Route-Prefix: {$this->routePrefix}" );
        }
    }

    protected function addGetParameter( $key, $value )
    {
        $currentResource = $this->request->getResource();

        $parseURL = parse_url( $currentResource );
        $path = $parseURL['path'];
        $query = !isset( $parseURL['query'] ) ? "" : $parseURL['query'];
        $query .= "&{$key}={$value}";

        $newResource = "{$path}" . (strlen($query)?"?{$query}":"");
        $this->request->setResource( $newResource );
    }

    /**
     *
     * Convenience method to transforms a Buzz\Message\Request into a
     * Buzz\Message\FormRequest and keeps host, resource and headers set in the
     * Request
     *
     * @param \Buzz\Message\Request $request
     */
    protected static function transformToFormRequest( \Buzz\Message\Request &$request )
    {
        $newRequest = new Buzz\Message\FormRequest();
        $newRequest->setHost( $request->getHost() );
        $newRequest->setResource( $request->getResource() );
        $newRequest->setHeaders( $request->getHeaders() );
        $request = $newRequest;
    }

    /**
     *
     * Logs information about the request and the response
     *
     */
    private function log()
    {
        $logFile = self::LOG_FILE . "_{$this->serviceName}.log";
        $message =  "\nREQ - {$this->serviceName}\t{$this->currentMethod}";
        $message .= "\nREQ - {$this->request->getMethod()} {$this->request->getResource()}";
        $message .= "\nREQ - Token: " . $this->tokenToUse;
        $message .= "\nREQ - User: " . ezaapUser::instance()->username;
        $message .= "\nRES - ContentLength: " . strlen($this->response->getContent()) . " Status Code: {$this->response->getStatusCode()}";
        eZLog::write( $message, $logFile );
    }
}

?>