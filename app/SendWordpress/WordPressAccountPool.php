<?php

namespace App\SendWordpress;

class WordPressAccountPool
{
    /** @var list<WordPressAccount>|null */
    private ?array $accounts = null;

    /**
     * @return list<WordPressAccount>
     */
    public function all(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $accounts = [];

        $primaryUser = (string) config('services.wordpress.user');
        $primaryPassword = (string) config('services.wordpress.password');

        if ($primaryUser !== '' && $primaryPassword !== '') {
            $accounts[] = new WordPressAccount($primaryUser, $primaryPassword, 'primary');
        }

        $secondaryUser = (string) config('services.wordpress.user_2');
        $secondaryPassword = (string) config('services.wordpress.password_2');

        if ($secondaryUser !== '' && $secondaryPassword !== '') {
            $accounts[] = new WordPressAccount($secondaryUser, $secondaryPassword, 'secondary');
        }

        $this->accounts = $accounts;

        return $this->accounts;
    }

    public function forIndex(int $index): WordPressAccount
    {
        $accounts = $this->all();

        if ($accounts === []) {
            throw new \RuntimeException('No hay cuentas WordPress configuradas (WORDPRESS_USER / WORDPRESS_PASSWORD).');
        }

        return $accounts[$index % count($accounts)];
    }

    public function hasMultiple(): bool
    {
        return count($this->all()) > 1;
    }
}
