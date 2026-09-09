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
            'confirmed' => 'Your leave request has been approved',
            'rejected' => 'Your leave request has been rejected',
            'cancelled' => 'Leave request cancelled',
            'cancelled-by-admin' => 'Your leave request has been cancelled',
            'reminder' => 'Reminder: Leave request awaiting your approval',
            'reminder-digest' => 'Reminder: Pending leave requests',
            'conversion-requested' => 'Leave conversion request awaiting your review',
            'conversion-approved' => 'Your leave conversion request has been approved',
            'conversion-rejected' => 'Your leave conversion request has been rejected',
            'quarterly-vl-impending' => 'Impending quarterly VL memo',
            'quarterly-vl-exceeded' => 'Quarterly VL memo — cap exceeded',
            'quarterly-vl-withdrawn' => 'Quarterly VL memo withdrawn',
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
