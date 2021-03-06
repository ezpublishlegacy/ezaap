<?php

class ezaapServiceProxyHandler extends ezaapService
{
    /**
     * @return type
     */
    public function availableThroughServiceModule()
    {
        return true;
    }

    /**
     * Pre trigger used to forward everything to the backend
     *
     * Adds the token to the request if [ProxySettings].AlwaysAddToken=true
     */
    public function preFwdRequest()
    {
        // forwards SF URI and GET parameters being forwarded by the /ezaap/service
        // ezpublish module
        $resource = $this->requestArguments['sf_uri'];
        $getString = "";
        foreach( $this->requestArguments['get_parameters'] as $key => $value )
        {
            $getString .= "{$key}={$value}";
        }
        if(strlen($getString))
        {
            $resource .= "&$getString";
        }

        $this->request->setResource($resource);
        $this->setRoutePrefix( '/ezaap/service/Proxy/Fwd' );

        // forwards POST parameters if available
        if( $_POST )
        {
            self::transformToFormRequest( $this->request );
            $this->request->addFields( $_POST );
        }

        // prevents Buzz from handling the redirect on his own
        $this->client->setMaxRedirects(0);
    }

    public function postFwdResponse()
    {
        // if redirect received from the backend, forwards the redirect
        if( $this->getResponseCode() == 302 )
        {
            $this->setRedirectURIAfterExecution( $this->response->getHeader( 'location' ) );
        }
        else
        {
            $this->responseContent = $this->response->getContent();
        }
    }
}
