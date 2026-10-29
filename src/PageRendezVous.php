<?php

declare(strict_types=1);

namespace Asin\Tma;

/**
 * Page de rendez-vous renvoyee par la regle de listing.
 */
final readonly class PageRendezVous
{
    /**
     * @param list<RendezVous> $rendezVous
     */
    public function __construct(
        public int $page,
        public int $parPage,
        public int $total,
        public array $rendezVous,
    ) {
    }

    public function nombreDePages(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) ceil($this->total / $this->parPage);
    }
}
