<?php

declare(strict_types=1);

namespace Emagma\McpAgentsBundle\Alert;

use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Notification\EmailNotificationInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;

/**
 * As an email, an alert is plain text: no template engine is needed on a server that renders nothing else.
 * The other channels (chat) take the subject and the content as any notification.
 *
 * @internal
 */
final class AgentAlertNotification extends Notification implements EmailNotificationInterface
{
    public function __construct(
        string $subject,
        private readonly string $sender,
    ) {
        parent::__construct($subject);
    }

    public function asEmailMessage(EmailRecipientInterface $recipient, ?string $transport = null): EmailMessage
    {
        return new EmailMessage(
            (new Email())
                ->from($this->sender)
                ->to($recipient->getEmail())
                ->subject($this->getSubject())
                ->text($this->getContent()),
        );
    }
}
