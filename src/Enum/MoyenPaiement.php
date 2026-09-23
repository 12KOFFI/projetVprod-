<?php

namespace App\Enum;

/**
 * Moyens de règlement proposés au candidat.
 *
 * Les deux derniers cas n'existent que pour piloter la passerelle de
 * simulation de façon déterministe (règle R5.10) : ils ne sont jamais proposés
 * dans l'interface et disparaîtront avec le branchement de la passerelle
 * réelle.
 */
enum MoyenPaiement: string
{
    case MOBILE_MONEY = 'mobile_money';
    case CARTE        = 'carte';
    case GUICHET      = 'guichet';

    case SIMULATION_ECHEC   = 'simulation_echec';
    case SIMULATION_ATTENTE = 'simulation_attente';

    public function libelle(): string
    {
        return match ($this) {
            self::MOBILE_MONEY       => 'Mobile Money',
            self::CARTE              => 'Carte bancaire',
            self::GUICHET            => 'Espèces au guichet',
            self::SIMULATION_ECHEC   => 'Simulation — échec',
            self::SIMULATION_ATTENTE => 'Simulation — en attente',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MOBILE_MONEY       => 'Orange Money, MTN MoMo, Moov Money, Wave',
            self::CARTE              => 'Visa ou Mastercard',
            self::GUICHET            => 'Règlement en espèces auprès du Trésor public',
            self::SIMULATION_ECHEC,
            self::SIMULATION_ATTENTE => 'Réservé aux tests de la passerelle simulée',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::MOBILE_MONEY       => 'mobile-screen-button',
            self::CARTE              => 'credit-card',
            self::GUICHET            => 'building-columns',
            default                  => 'flask',
        };
    }

    /**
     * Moyens réellement proposés au candidat.
     *
     * @return list<self>
     */
    public static function proposables(): array
    {
        return [self::MOBILE_MONEY, self::CARTE, self::GUICHET];
    }

    public function estProposable(): bool
    {
        return in_array($this, self::proposables(), true);
    }
}
