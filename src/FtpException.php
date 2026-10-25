<?php

declare(strict_types=1);

namespace DeployTool;

/** An FTP failure; the code is libcurl's error number. */
final class FtpException extends \RuntimeException
{
}
