<?php

namespace App\Service;

class Constant
{
    public $boolean =  ['OUI' => true, 'NON' => false];
    
    public $diplome = [
        'AUCUN' => 'AUCUN', 'CEPE' => 'CEPE', 'CQP' => 'CQP', 'CAP' => 'CAP', 'BEP' => 'BEP', 'BEPC' => 'BEPC', 'BP' => 'BP', 'BAC' => 'BAC', 'BT' => 'BT', 
        'DUT' => 'DUT', 'BTS' => 'BTS', 'DEUG' => 'DEUG', 'LICENCE' => 'LICENCE', 'MASTER' => 'MASTER', 'DOCTORAT' => 'DOCTORAT'
    ];
    public $niveauetude = [
        'CM2' => 'CM2', '6eme' => '6eme', '5eme' => '5eme', '4eme' => '4eme', '3eme' => '3eme', 'SECONDE' => 'SECONDE', 'PREMIERE' => 'PREMIERE', 'TERMINALE' => 'TERMINALE', 
        '1ere ANNEE' => '1ere ANNEE', '2eme ANNEE' => '2eme ANNEE', 'LICENCE' => 'LICENCE', 'MASTER' => 'MASTER', 'DOCTORAT' => 'DOCTORAT'
    ];
    
    public $typepiece = ['CNI' => 'CNI', 'ATTESTATION D\'IDENTITE' => 'ATTESTATION D\'IDENTITE'];
    public $sexe = ['MASCULIN' => 'MASCULIN', 'FEMININ' => 'FEMININ'];
    public $situationmat = ['CELIBATAIRE' => 'CELIBATAIRE', 'UNION LIBRE' => 'UNION LIBRE', 'MARIE(E)' => 'MARIE(E)', 'VEUF(VE)' => 'VEUF(VE)'];
    
    public $document_labels = [
        'fextrait' => [
            'icon' => 'file-alt',
            'text' => 'Extrait de naissance',
            'formats' => 'JPG, PNG, PDF',
            'accept' => 'image/jpeg,image/png,application/pdf',
            'required' => true
        ],
        'fpiece' => [
            'icon' => 'id-card',
            'text' => 'Pièce d\'identité',
            'formats' => 'JPG, PNG, PDF',
            'accept' => 'image/jpeg,image/png,application/pdf',
            'required' => true
        ],
        'fexperiencepro' => [
            'icon' => 'briefcase',
            'text' => 'Justificatif d\'experience professionnelle',
            'formats' => 'JPG, PNG, PDF',
            'accept' => 'image/jpeg,image/png,application/pdf',
            'required' => true
        ],
        'fcmu' => [
            'icon' => 'notes-medical',
            'text' => 'Attestation CMU',
            'formats' => 'JPG, PNG, PDF',
            'accept' => 'image/jpeg,image/png,application/pdf',
            'required' => true
        ],
        'fphoto' => [
            'icon' => 'camera',
            'text' => 'Photo d\'identite',
            'formats' => 'JPG, PNG',
            'accept' => 'image/jpeg,image/png',
            'required' => false
        ]
    ];
}