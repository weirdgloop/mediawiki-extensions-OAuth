<?php

class MWOAuthServer extends OAuthServer {

	/**
	 * Process a request_token request returns the request token on success. This
	 * also checks the IP restriction, which the OAuthServer method did not.
	 *
	 * @param MWOAuthRequest the request
	 * @return MWOAuthToken
	 */
	public function fetch_request_token( &$request ) {
		$this->get_version( $request );

		$consumer = $this->get_consumer( $request );

		$this->checkSourceIP( $consumer, $request );

		// no token required for the initial token request
		$token = NULL;

		$this->check_signature( $request, $consumer, $token );

		// Rev A change
		$callback = $request->get_parameter( 'oauth_callback' );
		$new_token = $this->data_store->new_request_token( $consumer, $callback );

		return $new_token;
	}

	/**
	 * process an access_token request
	 * returns the access token on success
	 */
	public function fetch_access_token( &$request ) {
		$this->get_version( $request );

		$consumer = $this->get_consumer( $request );

		$this->checkSourceIP( $consumer, $request );

		// requires authorized request token
		$token = $this->get_token( $request, $consumer, "request" );

		$this->check_signature( $request, $consumer, $token );

		// Rev A change
		$verifier = $request->get_parameter( 'oauth_verifier' );
		wfDebugLog( 'OAuth', __METHOD__ . ": verify code is '$verifier'" );
		$new_token = $this->data_store->new_access_token( $token, $consumer, $verifier );

		return $new_token;
	}

	/**
	 * Ensure the request comes from an approved IP address, if IP restriction has been
	 * setup by the Consumer.
	 *
	 * @param MWOAuthConsumer $consumer
	 * @param MWOAuthRequest $request
	 */
	private function checkSourceIP( $consumer, $request ) {
		// TODO: Cache the answers
		$restrictions = $consumer->get( 'restrictions' );
		$requestIP = $request->getSourceIP();
		$authorized = false;

		$ranges = $restrictions['IPAddresses']; #FormatJSON::decode( $restrictions, true );

		if ( $ranges === null && $restrictions !== '' ) {
			// assume the user entered a single range
			$ranges = array( $restrictions );
		}

		foreach ( $ranges as $range ) {
			if ( IP::isInRange( $requestIP, $range ) ) {
				$authorized = true;
				break;
			}
		}

		return $authorized;
	}

	/**
	 * The user has authorized the request by this consumer, with this request token. Update
	 * everything so that the consumer can swap the request token for an access token. Then
	 * generate the callback URL where we will redirect our user back to the consumer.
	 * @param String $consumerKey
	 * @param String $requestTokenKey
	 * @param User $mwUser user authorizing the request
	 * @return String the callback URL to redirect the user
	 */
	public function authorize( $consumerKey, $requestTokenKey, $mwUser ) {

		// Check that user and consumer are in good standing
		// TODO: Anything else? mwoauthmanagemyconsumers?
		if ( $mwUser->isBlocked() ) {
			throw new MWOAuthException( 'mwoauthserver-insufficient-rights' );
		}
		$consumer = $this->data_store->lookup_consumer( $consumerKey );
		if ( !$consumer ) {
			throw new MWOAuthException( 'mwoauthserver-bad-consumer' );
		}
		if ( $consumer->get( 'stage' ) !== MWOAuthConsumer::STAGE_APPROVED ) {
			//TODO other checks?
			throw new MWOAuthException( 'mwoauthserver-bad-consumer' );
		}

		// Generate and Update the tokens:
		// * Generate Access token, and add a pointer to it in the request token
		// * Generate a new Verification code, and add it to the request token
		// * Resave Request token with 
		$accessToken = MWOAuthDataStore::newToken();
		$verifyCode = MWCryptRand::generateHex( 32, true);
		$requestToken = $this->data_store->lookup_token( $consumer, 'request', $requestTokenKey );
		if ( !$requestToken || !( $requestToken instanceof MWOAuthToken ) ) {
			throw new MWOAuthException( 'mwoauthserver-invalid-request-token' );
		}
		$requestToken->addVerifyCode( $verifyCode );
		$requestToken->addAccessKey( $accessToken->key );
		$this->data_store->updateRequestToken( $requestToken, $consumer );

		// Make sure other extensions can switch out our user. CentralAuth may abort here
		// if there is no global account for this user.
		$this->getOAuthUser( $mwUser );

		// Add the Authorization to the database
		$cmra = MWOAuthConsumerAcceptance::newFromArray( array(
			'wiki'         => $consumer->get( 'wiki' ),
			'userId'       => $mwUser->getId(),
			'consumerId'   => $consumer->get( 'id' ),
			'accessToken'  => $accessToken->key,
			'accessSecret' => $accessToken->secret,
			'grants'       => $consumer->get( 'grants' ),
			'accepted'     => wfTimestampNow()
		) );
		$cmra->save( MWOAuthUtils::getCentralDB( DB_MASTER ) );

		wfDebugLog( 'OAuth', "Verification code {$requestToken->getVerifyCode()} for $requestTokenKey (client: $consumerKey)" );

		return $consumer->generateCallbackUrl( $requestToken->getVerifyCode(), $requestTokenKey );
	}

	/**
	 * Ensure we're using the correct user object, in case another extension wants to switch
	 * it out. After this, $user->getId() is used to store/get Grants for this user.
	 * @param User $user
	 */
	private function getOAuthUser( &$user ) {
		// Let CentralAuth, etc, change out the user
		if ( !wfRunHooks( 'OAuth-getUser', array( $user ) ) ) {
			throw new MWOAuthException( 'mwoauthserver-invalid-user-hookabort' );
		}
	}
}
