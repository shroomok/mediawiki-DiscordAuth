<?php

namespace DiscordAuth;

use DiscordAuth\AuthenticationProvider\DiscordAuth;
use Sanitizer;

class DiscordAuthHooks {

    /**
     * @param array|bool $user_info
     * @param $errorMessage
     */
	public function onWSOAuthAfterGetUser( &$user_info, &$errorMessage ): bool {
        if ( !$user_info ) {
            return false;
        }
        if ( !isset( $user_info[DiscordAuth::SOURCE] )) {
            return false;
        }
        if ( $user_info[DiscordAuth::SOURCE] !== DiscordAuth::DISCORD ) {
            return false;
        }

        if ( !isset( $user_info['email'] ) ) {
            return false;
        }

        if ( !Sanitizer::validateEmail( $user_info['email'] ) ) {
            return false;
        }

        $dbr = wfGetDB(DB_MASTER);
        $user = $dbr->select(
            'user',
            ['user_name'],
            ['user_email' => $user_info['email']],
            __METHOD__
        )->fetchObject();

        if ($user) {
            $user_info['name'] = $user->user_name;
        }

        return true;
	}

}
