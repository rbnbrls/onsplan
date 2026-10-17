<?php

namespace App\Mail;

use App\Models\FamilyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FamilyInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FamilyInvitation $invitation) {}

    public function envelope(): Envelope
    {
        $family = $this->invitation->family->name;

        return new Envelope(subject: "You're invited to join {$family} on onsplan");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.family-invitation',
            with: [
                'familyName' => $this->invitation->family->name,
                'inviter' => $this->invitation->inviter->name,
                'acceptUrl' => route('invite.show', $this->invitation->token),
            ],
        );
    }
}
