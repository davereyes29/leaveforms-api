<?php

namespace Tests\Feature;

use Tests\TestCase;

class DevMailPreviewTest extends TestCase
{
    public function test_mail_preview_is_hidden_outside_local(): void
    {
        $this->get('/dev/mail')->assertNotFound();
        $this->get('/dev/mail/confirmed')->assertNotFound();
    }

    public function test_mail_preview_index_and_templates_render_when_local(): void
    {
        $this->app['env'] = 'local';

        $this->get('/dev/mail')
            ->assertOk()
            ->assertSee('Leave mail previews')
            ->assertSee('Leave approved');

        $expected = [
            'submitted' => 'Leave request awaiting your approval',
            'sick-leave-submitted' => 'Sick Leave request awaiting your approval',
            'confirmed' => 'Your Vacation Leave request has been approved',
            'rejected' => 'Your Vacation Leave request has been rejected',
            'cancelled' => 'Vacation Leave request cancelled',
            'cancelled-by-admin' => 'Your Vacation Leave request has been cancelled',
            'reminder' => 'Vacation Leave request awaiting your approval',
            'reminder-digest' => 'Pending leave requests awaiting your approval',
            'conversion-requested' => 'Leave conversion request awaiting your review',
            'conversion-approved' => 'Your leave conversion request has been approved',
            'conversion-rejected' => 'Your leave conversion request has been rejected',
            'quarterly-vl-exceeded' => 'Approved Vacation Leave exceeds the quarterly limit',
        ];

        foreach ($expected as $template => $heading) {
            $this->get('/dev/mail/'.$template)
                ->assertOk()
                ->assertSee($heading);
        }
    }

    public function test_unknown_template_is_not_found_when_local(): void
    {
        $this->app['env'] = 'local';

        $this->get('/dev/mail/does-not-exist')->assertNotFound();
    }
}
