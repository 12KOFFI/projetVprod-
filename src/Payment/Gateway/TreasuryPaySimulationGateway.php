<?php

namespace App\Payment\Gateway;

use App\Enum\MoyenPaiement;
use App\Enum\StatutPaiement;
use App\Payment\Dto\PaymentRequest;
use App\Payment\Dto\PaymentResponse;
use App\Payment\PaymentGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Passerelle simulée, en attendant l'ouverture du compte Trésor Pay.
 *
 * Elle ne contacte aucun service : elle produit des réponses au format attendu
 * de la passerelle réelle, de façon DÉTERMINISTE afin que les parcours soient
 * reproductibles (règle métier R5.10) :
 *   - par défaut, le règlement aboutit ;
 *   - un montant dont les deux derniers chiffres valent 13 échoue ;
 *   - le moyen « simulation_echec » échoue, « simulation_attente » reste en attente.
 *
 * Aucune clé d'API ne figure ici : la passerelle réelle lira les siennes dans
 * .env.local (règle R5.9).
 */
class TreasuryPaySimulationGateway implements PaymentGatewayInterface
{
    private const NOM = 'tresor_pay_simulation';

    /** Montant piégé qui déclenche un échec, pour éprouver le parcours d'erreur. */
    private const SUFFIXE_ECHEC = '13';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $delaiSimulationMs = 0,
    ) {
    }

    public function initier(PaymentRequest $requete): PaymentResponse
    {
        $this->simulerLatence();

        $statut = $this->statutSimule($requete);
        $identifiant = $this->identifiantExterne();

        $this->logger->info('Paiement simulé initié', [
            'reference' => $requete->reference,
            'montant' => $requete->montant,
            'moyen' => $requete->moyenPaiement->value,
            'statut' => $statut->value,
        ]);

        return new PaymentResponse(
            succes: $statut !== StatutPaiement::ECHOUE,
            statut: $statut,
            referenceTransaction: $requete->reference,
            identifiantExterne: $identifiant,
            // Une passerelle réelle renverrait ici l'URL de sa page de
            // paiement ; la simulation confirme directement.
            urlRedirection: null,
            codeErreur: $statut === StatutPaiement::ECHOUE ? 'SIMU_INSUFFICIENT' : null,
            messageErreur: $statut === StatutPaiement::ECHOUE ? 'Provision insuffisante (simulation)' : null,
            reponseBrute: $this->reponseBrute('initier', $requete, $statut, $identifiant),
            horodatage: new \DateTimeImmutable(),
        );
    }

    public function verifier(string $referenceTransaction): PaymentResponse
    {
        $this->simulerLatence();

        // La simulation ne conserve pas d'état : une transaction déjà initiée
        // est considérée comme aboutie. Le service métier reste seul juge de
        // l'état réellement enregistré en base.
        return new PaymentResponse(
            succes: true,
            statut: StatutPaiement::REUSSI,
            referenceTransaction: $referenceTransaction,
            identifiantExterne: $this->identifiantExterne(),
            reponseBrute: ['operation' => 'verifier', 'reference' => $referenceTransaction, 'statut' => 'REUSSI'],
            horodatage: new \DateTimeImmutable(),
        );
    }

    public function annuler(string $referenceTransaction): PaymentResponse
    {
        return new PaymentResponse(
            succes: true,
            statut: StatutPaiement::ANNULE,
            referenceTransaction: $referenceTransaction,
            reponseBrute: ['operation' => 'annuler', 'reference' => $referenceTransaction],
            horodatage: new \DateTimeImmutable(),
        );
    }

    public function rembourser(string $referenceTransaction, string $montant): PaymentResponse
    {
        return new PaymentResponse(
            succes: true,
            statut: StatutPaiement::REMBOURSE,
            referenceTransaction: $referenceTransaction,
            identifiantExterne: $this->identifiantExterne(),
            reponseBrute: [
                'operation' => 'rembourser',
                'reference' => $referenceTransaction,
                'montant' => $montant,
            ],
            horodatage: new \DateTimeImmutable(),
        );
    }

    public function supporte(MoyenPaiement $moyen): bool
    {
        // La simulation accepte tout, y compris les moyens de test.
        return true;
    }

    public function nom(): string
    {
        return self::NOM;
    }

    public function estSimulation(): bool
    {
        return true;
    }

    private function statutSimule(PaymentRequest $requete): StatutPaiement
    {
        if ($requete->moyenPaiement === MoyenPaiement::SIMULATION_ECHEC) {
            return StatutPaiement::ECHOUE;
        }

        if ($requete->moyenPaiement === MoyenPaiement::SIMULATION_ATTENTE) {
            return StatutPaiement::EN_ATTENTE;
        }

        // Le montant est décimal : la partie entière porte le suffixe piégé.
        $entier = (string) (int) $requete->montant;

        if (str_ends_with($entier, self::SUFFIXE_ECHEC)) {
            return StatutPaiement::ECHOUE;
        }

        return StatutPaiement::REUSSI;
    }

    /**
     * @return array<string, mixed>
     */
    private function reponseBrute(
        string $operation,
        PaymentRequest $requete,
        StatutPaiement $statut,
        string $identifiant,
    ): array {
        return [
            'operation' => $operation,
            'gateway' => self::NOM,
            'transaction_id' => $identifiant,
            'merchant_reference' => $requete->reference,
            'amount' => $requete->montant,
            'currency' => $requete->devise,
            'payment_method' => $requete->moyenPaiement->value,
            'status' => strtoupper($statut->value),
            'customer' => [
                'name' => $requete->candidatNom,
                'phone' => $requete->candidatContact,
            ],
            'metadata' => $requete->metadonnees,
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
    }

    private function identifiantExterne(): string
    {
        return 'SIM-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function simulerLatence(): void
    {
        if ($this->delaiSimulationMs > 0) {
            usleep($this->delaiSimulationMs * 1000);
        }
    }
}
