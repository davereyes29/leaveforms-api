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
            'submitted' => 'Leave request pending your approval',
            'confirmed' => 'Your leave request was approved',
            'rejected' => 'Your leave request was rejected',
            'cancelled' => 'Leave request was cancelled',
            'reminder' => 'Reminder: leave request pending your approval',
            'reminder-digest' => 'Reminder: pending leave',
            'conversion-requested' => 'VL → SL conversion pending your review',
            'conversion-approved' => 'Your VL → SL conversion was approved',
            'conversion-rejected' => 'Your VL → SL conversion was rejected',
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
