<?php

namespace SMW\MediaWiki\Specials;

use SpecialPage;
use SMW\ApplicationFactory;
use Onoi\HttpRequest\HttpRequestFactory;
use Title;

/**
 * This class is the receiving endpoint for the `AsyncJobDispatchManager` invoked
 * job request.
 *
 * This special page is not expected to interact with a user and therefore it is
 * unlisted.
 *
 * @license GNU GPL v2+
 * @since   2.3
 *
 * @author mwjames
 */
class SpecialAsyncJobDispatcher extends SpecialPage {

	/**
	 * @var boolean
	 */
	private $allowedToModifyHttpHeader = true;

	/**
	 * @codeCoverageIgnore
	 */
	public function __construct() {
		parent::__construct( 'AsyncJobDispatcher', '', false );
	}

	/**
	 * @see SpecialPage::getGroupName
	 */
	protected function getGroupName() {
		return 'maintenance';
	}

	/**
	 * Only used during unit testing
	 *
	 * @since 2.3
	 */
	public function disallowToModifyHttpHeader() {
		$this->allowedToModifyHttpHeader = false;
	}

	/**
	 * @since 2.3
	 *
	 * @return string
	 */
	public static function getTargetURL() {
		return SpecialPage::getTitleFor( 'AsyncJobDispatcher')->getFullURL();
	}

	/**
	 * @since 2.3
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public static function getSessionToken( $key ) {
		return md5( $key . $GLOBALS['wgSecretKey'] );
	}

	/**
	 * @see SpecialPage::execute
	 */
	public function execute( $query ) {

		$this->getOutput()->disable();

		if ( $this->isHttpRequestMethod( 'GET' ) ) {
			return $this->modifyHttpHeader( "HTTP/1.0 400 Bad Request", 'The special page requires a POST/HEAD request.' );
		}

		$parameters = $this->getRequest()->getValues();

		if ( $this->isHttpRequestMethod( 'POST' ) && self::getSessionToken( $parameters['timestamp'] ) !== $parameters['sessionToken'] ) {
			return $this->modifyHttpHeader( "HTTP/1.0 400 Bad Request", 'Invalid or staled sessionToken was provided for the request' );
		}

		$this->modifyHttpHeader( "HTTP/1.0 202 Accepted" );

		if ( !isset( $parameters['async-job'] ) ) {
			return;
		}

		$type  = '';
		$title = '';

		list( $type, $title ) = explode( '|', $parameters['async-job'] );
		$title = Title::newFromDBkey( $title );

		if ( $title === null ) {
			wfDebugLog( 'smw', __METHOD__  . " invalid title" . "\n" );
			return;
		}

		switch ( $type ) {
			case 'SMW\UpdateJob':
				$this->runUpdateJob( $title, $parameters );
				break;
		}

		return true;
	}

	private function modifyHttpHeader( $header, $message = '' ) {

		if ( !$this->allowedToModifyHttpHeader ) {
			return null;
		}

		ignore_user_abort( true );
		header( $header );
		print $message;
		ob_flush();
		flush();
	}

	private function runUpdateJob( $title, $parameters ) {

		wfDebugLog( 'smw', __METHOD__ . ' dispatched for '.  $title->getPrefixedDBkey() . "\n" );

		$updateJob = ApplicationFactory::getInstance()->newJobFactory()->newUpdateJob(
			$title,
			$parameters
		);

		$updateJob->run();
	}

	// 1.19 doesn't have a getMethod
	private function isHttpRequestMethod( $key ) {

		if ( method_exists( $this->getRequest(), 'getMethod') ) {
			return $this->getRequest()->getMethod() == $key;
		}

		return isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] == $key : false;
	}

}
