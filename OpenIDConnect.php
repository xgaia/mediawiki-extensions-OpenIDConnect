<?php

/*
 * Copyright (c) 2015-2016 The MITRE Corporation
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

$wgExtensionFunctions[] = function () {
	if ( !class_exists( 'PluggableAuth' ) ) {
		die( '<b>Error:</b> This extension requires the PluggableAuth extension to be included first' );
	}
};

use \MediaWiki\Session\SessionManager;

class OpenIDConnect extends PluggableAuth {

	private $subject;
	private $issuer;

	/**
	 * @since 1.0
	 *
	 * @param &$id
	 * @param &$username
	 * @param &$realname
	 * @param &$email
	 */
	public function authenticate( &$id, &$username, &$realname, &$email ) {

		if ( !array_key_exists( 'SERVER_PORT', $_SERVER ) ) {
			wfDebug( "in authenticate, server port not set" . PHP_EOL );
			return false;
		}

		if ( !isset( $GLOBALS['wgOpenIDConnect_Config'] ) ) {
			wfDebug( "wgOpenIDConnect_Config not set" . PHP_EOL );
			return false;
		}

		try {

			$session = SessionManager::getGlobalSession();

			$iss = $session->get( 'iss' );

			if ( !is_null( $iss ) ) {

				if ( isset( $_REQUEST['code'] ) && isset( $_REQUEST['status'] ) ) {
					$session->remove( 'iss' );
				}

				if ( isset( $GLOBALS['wgOpenIDConnect_Config'][$iss] ) ) {

					$config = $GLOBALS['wgOpenIDConnect_Config'][$iss];

					if ( !isset( $config['clientID'] ) ||
						!isset( $config['clientsecret'] ) ) {
						wfDebug("OpenID Connect: clientID or clientsecret not set for " . $iss);
						$params = array(
							"uri" => urlencode( $_SERVER['REQUEST_URI'] ),
							"query" => urlencode( $_SERVER['QUERY_STRING'] )
						);
						self::redirect( "Special:SelectOpenIDConnectIssuer",
							$params, true );
						return false;
					}

				} else {
					wfDebug( 'Issuer ' . $iss .
						' does not exist in wgOpeIDConnect_Config'. PHP_EOL );
					return false;
				}

			} else {

				$iss_count = count( $GLOBALS['wgOpenIDConnect_Config'] );

				if ( $iss_count < 1 ) {
					return false;
				}

				if ( $iss_count == 1 ) {

					$iss = array_keys( $GLOBALS['wgOpenIDConnect_Config'] );
					$iss = $iss[0];

					$values = array_values( $GLOBALS['wgOpenIDConnect_Config'] );
					$config = $values[0];

					if ( !isset( $config['clientID'] ) ||
						!isset( $config['clientsecret'] ) ) {
						wfDebug("OpenID Connect: clientID or clientsecret not set for " .
							$iss);
						return false;
					}

				} else {

					$params = array(
						"uri" => urlencode( $_SERVER['REQUEST_URI'] ),
						"query" => urlencode( $_SERVER['QUERY_STRING'] )
					);
					self::redirect( "Special:SelectOpenIDConnectIssuer",
						$params, true );
					return false;
				}
			}

			$clientID = $config['clientID'];
			$clientsecret = $config['clientsecret'];

			$oidc = new OpenIDConnectClient( $iss, $clientID, $clientsecret );
			if ( isset( $_REQUEST['forcelogin'] ) ) {
				$oidc->addAuthParam( array( 'prompt' => 'login' ) );
			}
			if ( isset( $config['authparam'] ) &&
				is_array( $config['authparam'] ) ) {
				$oidc->addAuthParam( $config['authparam'] );
			}
			if ( isset( $config['scope'] ) ) {
				$scope = $config['scope'];
				if ( is_array( $scope ) ) {
					foreach ( $scope as $s ) {
						$oidc->addScope( $s );
					}
				} else {
					$oidc->addScope( $scope );
				}
			}
			if ( isset( $config['proxy'] ) ) {
				$oidc->setHttpProxy( $config['proxy'] );
			}
			if ( $oidc->authenticate() ) {

				$preferred_username =
					$oidc->requestUserInfo( "preferred_username" );
				$realname = $oidc->requestUserInfo( "name" );
				$email = $oidc->requestUserInfo( "email" );
				$this->subject = $oidc->requestUserInfo( 'sub' );
				$this->issuer = $oidc->getProviderURL();

				$username = $this->getName( $this->subject, $this->issuer );
				if ( !is_null( $username ) ) {
					return true;
				}

				if ( $GLOBALS['wgOpenIDConnect_MigrateUsers'] === true ) {
					$id = $this->getMigratedId( $preferred_username );
					if ( !is_null( $id ) ) {
						$this->saveExtraAttributes( $id );
						wfDebug( "Migrated user: " . $preferred_username );
						$username = $preferred_username;
						return true;
					}
				}

				$username = self::getAvailableUsername( $preferred_username,
					$realname, $email, $this->subject );

				return true;

			} else {
				$session->clear();
				return false;
			}
		} catch ( Exception $e ) {
			wfDebug( $e->__toString() . PHP_EOL );
			$session->clear();
			return false;
		}
	}

	/**
	 * @since 1.0
	 *
	 * @param User &$user
	 */
	public function deauthenticate( User &$user ) {
		if ( $GLOBALS['wgOpenIDConnect_ForceLogout'] === true ) {
			$returnto = 'Special:UserLogin';
			$params = array( 'forcelogin' => 'true' );
			self::redirect( $returnto, $params );
		}
		return true;
	}

	/**
	 * @since 1.0
	 *
	 * @param $id
	 */
	public function saveExtraAttributes( $id ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'user',
			array( // SET
				'subject' => $this->subject,
				'issuer' => $this->issuer
			), array( // WHERE
				'user_id' => $id
			), __METHOD__
		);
	}

	private static function getName( $subject, $issuer ) {
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'user',
			array( 'user_name' ),
			array(
				'subject' => $subject,
				'issuer' => $issuer
			), __METHOD__
		);
		if ( $row === false ) {
			return null;
		} else {
			return $row->user_name;
		}
	}

	private static function getMigratedId( $username ) {
		$nt = Title::makeTitleSafe( NS_USER, $username );
		if ( $nt === null ) {
			return null;
		}
		$username = $nt->getText();
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'user',
			array( 'user_id' ),
			array(
				'user_name' => $username,
				'subject' => null,
				'issuer' => null
			), __METHOD__
		);
		if ( $row === false ) {
			return null;
		} else {
			return $row->user_id;
		}
	}

	private static function getAvailableUsername( $preferred_username,
		$realname, $email, $subject ) {
		if ( strlen( $preferred_username ) > 0 ) {
			$name = $preferred_username;
		} elseif ( strlen ( $realname ) > 0 &&
			$GLOBALS['wgOpenIDConnect_UseRealNameAsUserName'] === true ) {
			$name = $realname;
		} elseif ( strlen( $email ) > 0 &&
			$GLOBALS['wgOpenIDConnect_UseEmailNameAsUserName'] === true ) {
			$pos = strpos ( $email, '@' );
			if ( $pos !== false && $pos > 0 ) {
				$name = substr( $email, 0, $pos );
			} else {
				$name = $email;
			}
		}
		$nt = Title::makeTitleSafe( NS_USER, $name );
		if ( is_null( $nt ) ) {
			$name = "User";
		} elseif ( is_null( User::idFromName( $name ) ) ) {
			return $nt->getText();
		} else {
			$name = $nt->getText();
		}
		$count = 1;
		while ( !is_null( User::idFromName( $name . $count ) ) ) {
			$count++;
		}
		return $name . $count;
	}

	public static function loadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionField( 'user', 'subject',
			__DIR__ . '/AddSubject.sql' );
		$updater->addExtensionField( 'user', 'issuer',
			__DIR__ . '/AddIssuer.sql' );
		return true;
	}

	/**
	 *
	 * @param $page
	 * @param $params
	 */
	private static function redirect( $page, $params = null, $doExit = false ) {
		$title = Title::newFromText( $page );
		if ( is_null( $title ) ) {
			$title = Title::newMainPage();
		}
		$url = $title->getFullURL();
		if ( is_array( $params ) && count( $params ) > 0 ) {
			foreach ( $params as $key => $value ) {
				$url = wfAppendQuery( $url, $key . '=' . $value );
			}
		}
		if ( Hooks::run( 'PluggableAuthRedirect', array( &$url ) ) ) {
			header('Location: ' . $url );
			if ( $doExit ) {
				exit;
			}
		}
	}
}

