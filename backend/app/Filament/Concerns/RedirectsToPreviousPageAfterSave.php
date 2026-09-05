<?php

namespace App\Filament\Concerns;

use App\Support\FilamentEditReturnUrl;

trait RedirectsToPreviousPageAfterSave
{
    public function mountRedirectsToPreviousPageAfterSave(): void
    {
        $queryReturn = request()->query('returnUrl');

        $candidate = is_string($queryReturn) && $queryReturn !== ''
            ? $queryReturn
            : ($this->previousUrl ?? url()->previous());

        if (FilamentEditReturnUrl::isUsableReturnUrl($candidate, url()->current())) {
            $this->previousUrl = $candidate;
            session()->put($this->returnUrlSessionKey(), $candidate);

            return;
        }

        $stored = session($this->returnUrlSessionKey());
        if (is_string($stored) && FilamentEditReturnUrl::isUsableReturnUrl($stored, url()->current())) {
            $this->previousUrl = $stored;
        }
    }

    protected function getRedirectUrl(): ?string
    {
        $url = $this->previousUrl ?? session($this->returnUrlSessionKey());

        if (is_string($url) && FilamentEditReturnUrl::isUsableReturnUrl($url, url()->current())) {
            session()->forget($this->returnUrlSessionKey());

            return $url;
        }

        return $this->getResource()::getUrl('index');
    }

    protected function returnUrlSessionKey(): string
    {
        $recordKey = '';
        if (method_exists($this, 'getRecord')) {
            try {
                $recordKey = (string) ($this->getRecord()?->getKey() ?? '');
            } catch (\Throwable) {
                $recordKey = '';
            }
        }

        return 'filament.edit_return_url.'.static::class.'.'.$recordKey;
    }
}
