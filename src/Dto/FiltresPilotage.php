<?php

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

/**
 * Filtres de l'écran de pilotage, lus dans l'URL (la vue se partage et se
 * recharge telle quelle).
 *
 * Simple objet de transport, sans persistance : chaque valeur est un
 * identifiant d'une entité existante (direction régionale, localité, centre,
 * filière, métier) ou une valeur fermée (sexe, période, étape). La cohérence
 * de la cascade (une localité de la direction choisie…) est vérifiée par
 * PilotageService, qui connaît le référentiel.
 */
final class FiltresPilotage
{
    public const ETAPES = ['preinscrits', 'inscrits', 'recevables', 'eligibles', 'admissibles', 'admis'];
    public const PERIODES = [
        'session' => 'Toute la session',
        '4s' => '4 dernières semaines',
        '8s' => '8 dernières semaines',
        'perso' => 'Dates choisies',
    ];
    public const SEXES = ['F' => 'Femmes', 'M' => 'Hommes'];

    /** Ordre de la cascade : retirer un niveau retire aussi ceux qui en dépendent. */
    private const DEPENDANCES = [
        'dr' => ['localite', 'centre'],
        'localite' => ['centre'],
        'filiere' => ['metier'],
        'periode' => ['du', 'au'],
    ];

    public ?int $dr = null;
    public ?int $localite = null;
    public ?int $centre = null;
    public ?int $filiere = null;
    public ?int $metier = null;
    public ?string $sexe = null;
    public string $periode = 'session';
    public ?\DateTimeImmutable $du = null;
    public ?\DateTimeImmutable $au = null;
    public string $etape = 'preinscrits';

    public static function depuisRequete(Request $requete, ?\DateTimeImmutable $aujourdhui = null): self
    {
        $f = new self();
        $q = $requete->query;

        foreach (['dr', 'localite', 'centre', 'filiere', 'metier'] as $cle) {
            $valeur = $q->getInt($cle);
            $f->{$cle} = $valeur > 0 ? $valeur : null;
        }

        $sexe = (string) $q->get('sexe', '');
        $f->sexe = array_key_exists($sexe, self::SEXES) ? $sexe : null;

        $etape = (string) $q->get('etape', '');
        $f->etape = in_array($etape, self::ETAPES, true) ? $etape : 'preinscrits';

        $periode = (string) $q->get('periode', 'session');
        $f->periode = array_key_exists($periode, self::PERIODES) ? $periode : 'session';

        $aujourdhui ??= new \DateTimeImmutable('today');
        if ($f->periode === '4s' || $f->periode === '8s') {
            $f->du = $aujourdhui->modify($f->periode === '4s' ? '-27 days' : '-55 days');
            $f->au = $aujourdhui;
        } elseif ($f->periode === 'perso') {
            $f->du = self::date((string) $q->get('du', ''));
            $f->au = self::date((string) $q->get('au', ''));
            if ($f->du !== null && $f->au !== null && $f->du > $f->au) {
                [$f->du, $f->au] = [$f->au, $f->du];
            }
            if ($f->du === null && $f->au === null) {
                $f->periode = 'session';
            }
        }

        return $f;
    }

    /**
     * Paramètres d'URL de la vue courante, sans les valeurs par défaut.
     *
     * @return array<string, string|int>
     */
    public function parametres(): array
    {
        $p = array_filter([
            'dr' => $this->dr,
            'localite' => $this->localite,
            'centre' => $this->centre,
            'filiere' => $this->filiere,
            'metier' => $this->metier,
            'sexe' => $this->sexe,
        ], static fn ($v) => $v !== null);

        if ($this->periode !== 'session') {
            $p['periode'] = $this->periode;
            if ($this->periode === 'perso') {
                if ($this->du) {
                    $p['du'] = $this->du->format('Y-m-d');
                }
                if ($this->au) {
                    $p['au'] = $this->au->format('Y-m-d');
                }
            }
        }
        if ($this->etape !== 'preinscrits') {
            $p['etape'] = $this->etape;
        }

        return $p;
    }

    /**
     * Paramètres de la vue courante privés d'un filtre et de ceux qui en
     * dépendent dans la cascade (pastille « retirer »).
     *
     * @return array<string, string|int>
     */
    public function parametresSans(string $cle): array
    {
        $p = $this->parametres();
        foreach ([$cle, ...(self::DEPENDANCES[$cle] ?? [])] as $retire) {
            unset($p[$retire]);
        }

        return $p;
    }

    /**
     * Paramètres de la vue courante avec une valeur remplacée.
     *
     * @return array<string, string|int>
     */
    public function parametresAvec(string $cle, string|int $valeur): array
    {
        return [$cle => $valeur] + $this->parametresSans($cle);
    }

    /** Un filtre de périmètre est-il posé (hors étape analysée) ? */
    public function estFiltre(): bool
    {
        $p = $this->parametres();
        unset($p['etape']);

        return $p !== [];
    }

    private static function date(string $saisie): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);

        return $date !== false && $date->format('Y-m-d') === $saisie ? $date : null;
    }
}
