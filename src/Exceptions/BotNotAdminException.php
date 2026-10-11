<?php

namespace AfazTech\ReactorJoin\Exceptions;

/**
 * Thrown when the bot is not an administrator in the target channel
 * and therefore cannot verify membership.
 */
class BotNotAdminException extends JoinException
{
}
