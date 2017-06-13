<?php
/**
 *
 *
 * Created on Dec 22, 2014
 *
 * Copyright © 2014 Wikimedia Foundation and contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Message subclass that prepends wikitext for API help.
 *
 * This exists so the apihelp-*-paramvalue-*-* messages don't all have to
 * include markup wikitext while still keeping the
 * 'APIGetParamDescriptionMessages' hook simple.
 *
 * @since 1.25
 */
class ApiHelpParamValueMessage extends Message {

	protected $paramValue;

	/**
	 * @see Message::__construct
	 *
	 * @param string $paramValue Parameter value being documented
	 * @param string $text Message to use.
	 * @param array $params Parameters for the message.
	 * @throws InvalidArgumentException
	 */
	public function __construct( $paramValue, $text, $params = [] ) {
		parent::__construct( $text, $params );
		$this->paramValue = $paramValue;
	}

	/**
	 * Fetch the parameter value
	 * @return string
	 */
	public function getParamValue() {
		return $this->paramValue;
	}

	/**
	 * Fetch the message.
	 * @return string
	 */
	public function fetchMessage() {
		if ( $this->message === null ) {
			$this->message = ";<span dir=\"ltr\" lang=\"en\">{$this->paramValue}</span>:"
				. parent::fetchMessage();
		}
		return $this->message;
	}

}
