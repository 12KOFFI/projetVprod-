<?php

namespace App\Payment;

use App\Payment\Exception\ConfigurationPasserelleException;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Choisit la passerelle selon PAIEMENT_PASSERELLE (fabrique déclarée dans
 * services.yaml pour PaymentGatewayInterface).
 *
 * Registre automatique : toute classe qui porte #[AutoconfigureTag(self::TAG)]
 * y est inscrite sous sa clé (PaymentGatewayInterface::cle()). Brancher un
 * nouveau fournisseur ne modifie donc ni ce fichier ni la configuration :
 * seule la valeur de PAIEMENT_PASSERELLE change. Les passerelles sont
 * instanciées à la demande : seule la passerelle active est construite.
 */
final class SelecteurPasserelle
{
    public const TAG = 'app.passerelle_paiement';
    public const PAR_DEFAUT = 'simulation';

    public function __construct(
        #[TaggedLocator(self::TAG, defaultIndexMethod: 'cle')]
        private readonly ServiceLocator $passerelles,
        private readonly string $passerelle,
    ) {
    }

    public function passerelle(): PaymentGatewayInterface
    {
        $cle = trim($this->passerelle) !== '' ? trim($this->passerelle) : self::PAR_DEFAUT;

        if (!$this->passerelles->has($cle)) {
            throw ConfigurationPasserelleException::passerelleInconnue($cle, $this->disponibles());
        }

        return $this->passerelles->get($cle);
    }

    /**
     * @return string[] clés des passerelles installées
     */
    public function disponibles(): array
    {
        return array_keys($this->passerelles->getProvidedServices());
    }
}
