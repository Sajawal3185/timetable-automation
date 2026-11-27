<?php

namespace App\Exceptions;

use RuntimeException;

/** An email couldn't be handed to the provider (EmailError in #93's email_sender.py). The API answers 503. */
class EmailException extends RuntimeException {}
