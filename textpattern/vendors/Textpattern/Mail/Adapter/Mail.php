<?php

/*
 * Textpattern Content Management System
 * http://textpattern.com
 *
 * Copyright (C) 2013 The Textpattern Development Team
 *
 * This file is part of Textpattern.
 *
 * Textpattern is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2.
 *
 * Textpattern is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Textpattern. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Adapter for PHP's mail function.
 *
 * @since   4.6.0
 * @package Mail
 */

class Textpattern_Mail_Adapter_Mail implements Textpattern_Mail_AdapterInterface
{
	/**
	 * The email fields.
	 *
	 * @var Textpattern_Mail_Message
	 */

	protected $mail;

	/**
	 * Encoded email fields.
	 *
	 * @var Textpattern_Mail_Message
	 */

	protected $encoded;

	/**
	 * Line separator.
	 *
	 * @var string
	 */

	protected $separator = "\n";

	/**
	 * The message encoding.
	 *
	 * @var string
	 */

	protected $charset = 'UTF-8';

	/**
	 * SMTP envelope sender address.
	 *
	 * @var string|bool
	 */

	protected $smtpFrom = false;

	/**
	 * The encoder.
	 *
	 * @var Textpattern_Mail_Encode
	 */

	protected $encoder;

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		$this->mail = new Textpattern_Mail_Message();
		$this->encoded = new Textpattern_Mail_Message();
		$this->encoder = new Textpattern_Mail_Encode();

		if (IS_WIN)
		{
			$this->separator = "\r\n";
		}

		if (get_pref('override_emailcharset') && is_callable('utf8_decode'))
		{
			$this->charset = 'ISO-8859-1';
			$this->mail->headers['Content-Type'] = 'text/plain; charset="ISO-8859-1"';
			$this->encoded->headers['Content-Type'] = 'text/plain; charset="ISO-8859-1"';
		}

		if (filter_var(get_pref('smtp_from'), FILTER_VALIDATE_EMAIL))
		{
			if (IS_WIN)
			{
				ini_set('sendmail_from', get_pref('smtp_from'));
			}
			else if (!ini_get('safe_mode'))
			{
				$this->smtpFrom = get_pref('smtp_from');
			}
		}
	}

	/**
	 * Sets or gets a message field.
	 *
	 * @param  string $name The field
	 * @param  array  $args Arguments
	 * @return Textpattern_Mail_AdapterInterface
	 * @throws Textpattern_Mail_Exception
	 */

	public function __call($name, array $args = null)
	{
		if (!$args)
		{
			if (property_exists($this->mail, $name) === false)
			{
				throw new Textpattern_Mail_Exception(gTxt('invalid_argument', array('{name}' => 'name')));
			}

			return $this->mail->$name;
		}

		if (isset($args[1]))
		{
			return $this->addAddress($name, $args[0], $args[1]);
		}

		return $this->addAddress($name, $args[0]);
	}

	/**
	 * {@inheritdoc}
	 */

	public function subject($subject)
	{
		if (!is_scalar($subject) || (string) $subject === '')
		{
			throw new Textpattern_Mail_Exception(gTxt('invalid_argument', array('{name}' => 'subject')));
		}

		$this->mail->subject = $subject;
		
		if ($this->charset != 'UTF-8')
		{
			$subject = utf8_decode($subject);
		}

		$this->encoded->subject = $this->encoder->header($this->encoder->escapeHeader($subject), 'text');
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */

	public function body($body)
	{
		$this->mail->body = $body;

		if ($this->charset != 'UTF-8')
		{
			$body = utf8_decode($body);
		}

		$body = str_replace("\r\n", "\n", $body);
		$body = str_replace("\r", "\n", $body);
		$body = str_replace("\n", $this->separator, $body);
		$this->encoded->body = deNull($body);

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */

	public function header($name, $value)
	{
		if ((string) $value === '' || !preg_match('/^[\041-\071\073-\176]+$/', $name))
		{
			throw new Textpattern_Mail_Exception(gTxt('invalid_header'));
		}

		$this->mail->headers[$name] = $value;
		$this->encoded->headers[$name] = $this->encoder->header($this->encoder->escapeHeader($value), 'phrase');
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */

	public function send()
	{
		if (is_disabled('mail'))
		{
			throw new Textpattern_Mail_Exception(gTxt('disabled_function', array('{name}' => 'mail')));
		}

		if (!$this->mail->from || !$this->mail->to)
		{
			throw new Textpattern_Mail_Exception(gTxt('from_or_to_address_missing'));
		}

		$headers = array();
		$headers['From'] = $this->encoded->from;

		if ($this->encoded->cc)
		{
			$headers['Cc'] = $this->encoded->cc;
		}

		if ($this->encoded->bcc)
		{
			$headers['Bcc'] = $this->encoded->bbc;
		}

		if ($this->encoded->replyTo)
		{
			$headers['Reply-to'] = $this->encoded->replyTo;
		}

		$headers += $this->encoded->headers;

		foreach ($headers as $name => &$value)
		{
			$value = $name.': '.$value;
		}

		$headers = join($this->separator, $headers).$this->separator;

		if ($this->smtpFrom)
		{
			if (mail($this->encoded->to, $this->encoded->subject, $this->encoded->body, $headers, '-f'.$this->smtpFrom) === false)
			{
				throw new Textpattern_Mail_Exception(gTxt('sending_failed'));
			}
		}

		if (mail($this->encoded->to, $this->encoded->subject, $this->encoded->body, $headers) === false)
		{
			throw new Textpattern_Mail_Exception(gTxt('sending_failed'));
		}

		return $this;
	}

	/**
	 * Adds an address to the specified field.
	 *
	 * @param  string $field   The field
	 * @param  string $address The email address
	 * @param  string $name    The name
	 * @return Textpattern_Mail_AdapterInterface
	 */

	protected function addAddress($field, $address, $name = '')
	{
		if (filter_var($address, FILTER_VALIDATE_EMAIL))
		{
			$this->mail->$field = array_merge($this->mail->$field, array($address => $name));
			$this->encoded->$field = $this->encoder->addressList($this->mail->$field);
			return $this;
		}

		throw new Textpattern_Mail_Exception(gTxt('invalid_argument', array('{name}' => 'address')));
	}
}