/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import "./styles/app.css";

import Alpine from "alpinejs";
import listeDeroulante, { formaterNumero } from "./composants/liste-deroulante";
import {
    Chart,
    DoughnutController,
    ArcElement,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Filler,
    Tooltip,
} from "chart.js";

// Seuls les éléments réellement utilisés sont enregistrés : anneau, barres
// horizontales et courbe. Chart.js ne charge rien d'autre.
Chart.register(
    DoughnutController,
    ArcElement,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Filler,
    Tooltip
);

// Typographie et encre du projet : Plus Jakarta Sans, texte secondaire slate-600.
Chart.defaults.font.family = "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = "#475569";

/**
 * Palette imposée des graphiques.
 *
 * Les couleurs par défaut de Chart.js sont un arc-en-ciel étranger à la charte :
 * elles réintroduiraient des teintes absentes de `tailwind.config.js`. Toute
 * série reçoit donc explicitement ces valeurs, dérivées du bleu marine DAIP
 * (`daipBlue`) pour les séries sans signification propre, et des couleurs
 * sémantiques du projet quand la teinte porte un sens.
 */
const PALETTE_GRAPHIQUE = [
    "#0B669D", // indigo/blue-600 — daipBlue
    "#5FA6C9", // blue-400
    "#B9DDED", // blue-200
    "#075786", // blue-700
    "#8DC4DE", // blue-300
    "#03476F", // blue-800
];

/** Teintes sémantiques, à nommer explicitement dans `data-couleurs`. */
const COULEURS_SEMANTIQUES = {
    succes: "#22C55E", // green-500
    attente: "#F59E0B", // amber-500
    refus: "#EF4444", // red-500
    neutre: "#94A3B8", // slate-400
};

/** Grille et axes discrets : ils situent la valeur sans la concurrencer. */
const ENCRE = { texte: "#0F172A", grille: "#E2E8F0", axe: "#CBD5E1" };

/** Infobulle commune : fond encre, chiffre en clair, sans pastille de couleur. */
const INFOBULLE = {
    backgroundColor: "#0F172A",
    titleColor: "#FFFFFF",
    bodyColor: "#E2E8F0",
    titleFont: { weight: "600" },
    padding: 10,
    cornerRadius: 6,
    displayColors: false,
    callbacks: {
        label: (contexte) => {
            // Anneau : un nombre ; barres horizontales : x ; courbe : y.
            const valeur = typeof contexte.parsed === "number"
                ? contexte.parsed
                : (contexte.chart.options.indexAxis === "y" ? contexte.parsed.x : contexte.parsed.y);
            return `${valeur} dossier${valeur > 1 ? "s" : ""}`;
        },
    },
};

/** L'utilisateur a-t-il demandé de réduire les animations ? */
function mouvementReduit() {
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/** Animation courte à la première apparition ; aucune si l'utilisateur la refuse. */
function animation() {
    return mouvementReduit() ? false : { duration: 450, easing: "easeOutQuart" };
}

/** Anneau : rotation et ouverture depuis le centre, à l'entrée comme à chaque changement d'étape. */
function animationAnneau() {
    return mouvementReduit()
        ? false
        : { duration: 900, easing: "easeOutQuart", animateRotate: true, animateScale: true };
}

/** Libellé d'axe tronqué : le nom complet reste dans l'infobulle. */
function tronquer(libelle, longueur = 30) {
    return libelle.length > longueur ? `${libelle.slice(0, longueur - 1)}…` : libelle;
}

/**
 * Configuration Chart.js d'un graphique, selon son type (`data-graphique`) :
 * - « anneau » (défaut) : répartition en parts, palette DAIP ou couleurs sémantiques ;
 * - « barres » : effectifs comparés, barres horizontales d'une seule teinte ;
 * - « courbe » : évolution dans le temps, une seule série.
 */
function configurationGraphique(type, libelles, donnees, couleurs, options = {}) {
    if (type === "barres") {
        return {
            type: "bar",
            data: {
                labels: libelles,
                datasets: [
                    {
                        data: donnees,
                        backgroundColor: PALETTE_GRAPHIQUE[0],
                        hoverBackgroundColor: PALETTE_GRAPHIQUE[3],
                        borderRadius: 4,
                        borderSkipped: "start",
                        maxBarThickness: 28,
                        categoryPercentage: 0.82,
                        barPercentage: 0.9,
                    },
                ],
            },
            options: {
                indexAxis: "y",
                responsive: true,
                maintainAspectRatio: false,
                animation: animation(),
                plugins: { legend: { display: false }, tooltip: INFOBULLE },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: ENCRE.grille },
                        border: { display: false },
                    },
                    y: {
                        grid: { display: false },
                        border: { color: ENCRE.axe },
                        ticks: {
                            color: ENCRE.texte,
                            autoSkip: false,
                            callback(valeur) {
                                return tronquer(this.getLabelForValue(valeur));
                            },
                        },
                    },
                },
            },
        };
    }

    if (type === "courbe") {
        return {
            type: "line",
            data: {
                labels: libelles,
                datasets: [
                    {
                        data: donnees,
                        borderColor: PALETTE_GRAPHIQUE[0],
                        borderWidth: 2,
                        // Aire discrète sous la courbe : le bleu DAIP, de 12 % à 0 %.
                        fill: "origin",
                        backgroundColor: (contexte) => {
                            const { ctx, chartArea } = contexte.chart;
                            if (!chartArea) {
                                return "transparent";
                            }
                            const degrade = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                            degrade.addColorStop(0, "rgba(11, 102, 157, 0.12)");
                            degrade.addColorStop(1, "rgba(11, 102, 157, 0)");
                            return degrade;
                        },
                        // Interpolation monotone : sans elle, la courbe plonge
                        // sous zéro entre deux semaines creuses.
                        cubicInterpolationMode: "monotone",
                        // La semaine la plus chargée est mise en valeur.
                        pointRadius: (contexte) => (contexte.raw === Math.max(...donnees) && contexte.raw > 0 ? 6 : 4),
                        pointHoverRadius: 7,
                        pointBackgroundColor: "#FFFFFF",
                        pointBorderColor: PALETTE_GRAPHIQUE[0],
                        pointBorderWidth: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: animation(),
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...INFOBULLE,
                        callbacks: {
                            ...INFOBULLE.callbacks,
                            // « Semaine du 05/10 » plutôt que « 05/10 » seul.
                            title: (elements) => `${options.prefixe ?? ""}${elements[0].label}`,
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: ENCRE.grille },
                        border: { display: false },
                        title: options.axeY ? { display: true, text: options.axeY, color: ENCRE.texte } : undefined,
                    },
                    x: {
                        grid: { display: false },
                        border: { color: ENCRE.axe },
                        ticks: { maxRotation: 0, autoSkipPadding: 12 },
                        title: options.axeX ? { display: true, text: options.axeX, color: ENCRE.texte } : undefined,
                    },
                },
            },
        };
    }

    return {
        type: "doughnut",
        data: {
            labels: libelles,
            datasets: [
                {
                    data: donnees,
                    backgroundColor: couleurs,
                    borderColor: "#FFFFFF",
                    borderWidth: 2,
                    // Une part survolée se détache légèrement de l'anneau.
                    hoverOffset: mouvementReduit() ? 0 : 6,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: animationAnneau(),
            layout: { padding: 6 },
            cutout: "62%",
            plugins: {
                // La légende est rendue en Twig sous le graphique, avec des
                // effectifs chiffrés : celle de Chart.js ferait doublon.
                legend: { display: false },
                tooltip: INFOBULLE,
            },
        },
    };
}

/**
 * Graphiques des écrans de pilotage.
 *
 * Les données ne sont pas écrites en JavaScript dans le gabarit : le canvas
 * porte un attribut `data-valeurs` (JSON `{libellé: effectif}`), un
 * `data-graphique` (anneau, barres, courbe) et, au besoin, un `data-couleurs`
 * listant des clés sémantiques. Un seul point d'entrée évite un `<script>` par
 * graphique ; les chiffres restent écrits en Twig à côté de chaque graphique.
 */
/** Couleurs d'une série : clés sémantiques (« palette », « neutre »…) ou palette DAIP par défaut. */
function couleursSerie(cles, nombre) {
    if (cles) {
        return cles
            .split(",")
            .map((cle, index) => COULEURS_SEMANTIQUES[cle.trim()] ?? PALETTE_GRAPHIQUE[index % PALETTE_GRAPHIQUE.length]);
    }

    return Array.from({ length: nombre }, (_, index) => PALETTE_GRAPHIQUE[index % PALETTE_GRAPHIQUE.length]);
}

/**
 * Données à afficher d'un canvas : `data-valeurs` ({libellé: effectif}), ou,
 * pour un anneau des écrans de pilotage, la série de l'étape courante dans
 * `data-series` ({étape: {libelles, valeurs, couleurs}}).
 */
function donneesCanvas(canvas, etape = null) {
    try {
        if (canvas.dataset.series) {
            const series = JSON.parse(canvas.dataset.series);
            const serie = series[etape ?? canvas.dataset.etape] ?? { libelles: [], valeurs: [], couleurs: "" };

            return { libelles: serie.libelles, valeurs: serie.valeurs, cles: serie.couleurs };
        }

        const valeurs = JSON.parse(canvas.dataset.valeurs);

        return { libelles: Object.keys(valeurs), valeurs: Object.values(valeurs), cles: canvas.dataset.couleurs };
    } catch (e) {
        return null;
    }
}

/**
 * Monte les graphiques d'une zone de la page (toute la page par défaut, ou un
 * fragment remplacé sans rechargement). Un canvas déjà monté est ignoré.
 */
function monterGraphiques(racine = document) {
    racine.querySelectorAll("canvas[data-valeurs], canvas[data-series]").forEach((canvas) => {
        if (Chart.getChart(canvas)) {
            return;
        }

        const donnees = donneesCanvas(canvas);

        // Un graphique sans donnée afficherait un disque vide et trompeur :
        // le gabarit prévoit un état vide à la place, on n'instancie rien.
        // Un anneau à étapes est monté quand même : une autre étape peut
        // avoir des données.
        if (donnees === null || (donnees.libelles.length === 0 && !canvas.dataset.series)) {
            return;
        }

        new Chart(
            canvas,
            configurationGraphique(
                canvas.dataset.graphique || "anneau",
                donnees.libelles,
                donnees.valeurs,
                couleursSerie(donnees.cles, donnees.libelles.length),
                {
                    prefixe: canvas.dataset.prefixe,
                    axeX: canvas.dataset.axeX,
                    axeY: canvas.dataset.axeY,
                }
            )
        );
    });
}

/** Démonte les graphiques d'une zone avant de la remplacer. */
function demonterGraphiques(racine) {
    racine.querySelectorAll("canvas").forEach((canvas) => Chart.getChart(canvas)?.destroy());
}

/**
 * Changement d'étape analysée (cartes des étapes, écrans de pilotage) : les
 * anneaux à étapes passent à la série de la nouvelle étape, avec l'animation
 * de rotation de Chart.js.
 */
document.addEventListener("etape-change", (evenement) => {
    document.querySelectorAll("canvas[data-series]").forEach((canvas) => {
        const graphique = Chart.getChart(canvas);
        const donnees = donneesCanvas(canvas, evenement.detail.etape);

        if (!graphique || donnees === null) {
            return;
        }

        canvas.dataset.etape = evenement.detail.etape;
        graphique.data.labels = donnees.libelles;
        graphique.data.datasets[0].data = donnees.valeurs;
        graphique.data.datasets[0].backgroundColor = couleursSerie(donnees.cles, donnees.libelles.length);
        graphique.reset();
        graphique.update();
    });
});

document.addEventListener("DOMContentLoaded", () => monterGraphiques());

/** Nombre au format français : espace insécable fine pour les milliers. */
const FORMAT_NOMBRE = new Intl.NumberFormat("fr-FR");

/**
 * Chiffre qui défile de 0 à sa valeur à l'apparition, une seule fois. Le
 * gabarit écrit la valeur finale : sans JavaScript ou avec les animations
 * réduites, elle s'affiche telle quelle.
 */
window.compteur = function (valeur) {
    return {
        affiche: FORMAT_NOMBRE.format(valeur),

        init() {
            if (mouvementReduit() || valeur === 0) {
                return;
            }

            const debut = performance.now();
            const duree = 900;
            const pas = (instant) => {
                const avancement = Math.min(1, (instant - debut) / duree);
                this.affiche = FORMAT_NOMBRE.format(Math.round(valeur * (1 - Math.pow(1 - avancement, 3))));
                if (avancement < 1) {
                    requestAnimationFrame(pas);
                }
            };

            this.affiche = "0";
            requestAnimationFrame(pas);
        },
    };
};

/**
 * Étape analysée sur un écran de pilotage. `choisir()` émet `etape-change`,
 * écouté plus haut pour les anneaux et par l'écran Indicateurs pour l'URL.
 */
window.etapeAnalysee = function (initiale = "preinscrits") {
    return {
        etape: initiale,

        choisir(cle) {
            if (cle === this.etape) {
                return;
            }
            this.etape = cle;
            this.$dispatch("etape-change", { etape: cle });
        },
    };
};

/**
 * Liste paginée côté navigateur, avec défilement automatique des pages
 * (tableau des centres du tableau de bord).
 *
 * `lignes` : texte de recherche de chaque ligne, en minuscules, dans l'ordre
 * d'affichage. Le défilement s'arrête dès que l'utilisateur interagit
 * (survol, focus, toucher, clic) et ne reprend que par le bouton Lecture ; il
 * ne démarre pas si l'utilisateur a demandé de réduire les animations.
 */
window.paginationAuto = function ({ lignes, parPage = 5, delai = 6000 }) {
    return {
        page: 0,
        q: "",
        pause: mouvementReduit(),
        ecoule: 0,
        minuteur: null,

        init() {
            this.minuteur = setInterval(() => {
                if (this.pause || document.hidden || this.nbPages() < 2) {
                    return;
                }
                this.ecoule += 100;
                if (this.ecoule >= delai) {
                    this.ecoule = 0;
                    this.page = (this.page + 1) % this.nbPages();
                }
            }, 100);
        },

        destroy() {
            clearInterval(this.minuteur);
        },

        /** Index des lignes retenues par la recherche. */
        retenues() {
            const q = this.q.trim().toLowerCase();

            return lignes.reduce((retenues, texte, index) => {
                if (q === "" || texte.includes(q)) {
                    retenues.push(index);
                }

                return retenues;
            }, []);
        },

        nbPages() {
            return Math.max(1, Math.ceil(this.retenues().length / parPage));
        },

        visible(index) {
            const position = this.retenues().indexOf(index);

            return position !== -1 && Math.floor(position / parPage) === this.page;
        },

        aller(page) {
            const nb = this.nbPages();
            this.page = ((page % nb) + nb) % nb;
            this.arreter();
        },

        chercher() {
            this.page = 0;
            this.arreter();
        },

        arreter() {
            this.pause = true;
            this.ecoule = 0;
        },

        basculer() {
            this.pause = !this.pause;
            this.ecoule = 0;
        },

        progression() {
            return this.pause ? 0 : Math.min(100, (this.ecoule / delai) * 100);
        },

        plage() {
            const total = this.retenues().length;
            if (total === 0) {
                return "Aucun centre trouvé";
            }
            const debut = this.page * parPage + 1;

            return `Centres ${debut}–${Math.min(debut + parPage - 1, total)} sur ${total}`;
        },
    };
};

/**
 * Écran Indicateurs de l'administration : filtres, pastilles et parts
 * d'anneau appliqués sans rechargement. Le contenu est redemandé au serveur
 * (en-tête X-Requested-With : il ne renvoie que le fragment), remplacé, puis
 * ses graphiques remontés ; l'URL suit, pour partager et recharger la vue.
 */
window.indicateursDynamiques = function () {
    return {
        chargement: false,
        erreur: false,
        derniereUrl: null,

        init() {
            window.addEventListener("popstate", () => this.charger(window.location.href, false));
        },

        /** L'étape choisie à l'écran reste dans l'URL partagée. */
        etapeChangee(etape) {
            const url = new URL(window.location.href);
            if (etape === "preinscrits") {
                url.searchParams.delete("etape");
            } else {
                url.searchParams.set("etape", etape);
            }
            history.replaceState(null, "", url);
        },

        /** Clic sur un lien de filtre : pastille, réinitialisation, part d'anneau. */
        lien(evenement) {
            const a = evenement.target.closest("a[data-filtre]");
            if (!a || evenement.button !== 0 || evenement.metaKey || evenement.ctrlKey || evenement.shiftKey || evenement.altKey) {
                return;
            }
            evenement.preventDefault();

            // Le lien a été écrit par le serveur avec l'étape de l'époque :
            // l'étape choisie depuis à l'écran (gardée dans l'URL) l'emporte.
            const url = new URL(a.href);
            const etape = new URL(window.location.href).searchParams.get("etape");
            if (etape) {
                url.searchParams.set("etape", etape);
            } else {
                url.searchParams.delete("etape");
            }
            this.charger(url.toString());
        },

        soumettre(evenement) {
            const formulaire = evenement.target;
            const parametres = new URLSearchParams();
            new FormData(formulaire).forEach((valeur, cle) => {
                // Valeurs par défaut omises, comme dans FiltresPilotage::parametres().
                if (valeur !== "" && !(cle === "etape" && valeur === "preinscrits") && !(cle === "periode" && valeur === "session")) {
                    parametres.append(cle, valeur);
                }
            });
            const chaine = parametres.toString();
            this.charger(formulaire.action + (chaine ? `?${chaine}` : ""));
        },

        async charger(url, historique = true) {
            this.chargement = true;
            this.erreur = false;
            this.derniereUrl = url;

            try {
                const reponse = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" } });
                if (!reponse.ok) {
                    throw new Error("Chargement impossible");
                }
                const html = await reponse.text();
                const zone = this.$refs.contenu;

                demonterGraphiques(zone);
                zone.innerHTML = html;
                // Alpine initialise seul le nouveau contenu ; les graphiques
                // attendent qu'il soit en place.
                this.$nextTick(() => monterGraphiques(zone));

                if (historique) {
                    history.pushState(null, "", url);
                }
            } catch (e) {
                this.erreur = true;
            } finally {
                this.chargement = false;
            }
        },

        reessayer() {
            if (this.derniereUrl) {
                this.charger(this.derniereUrl);
            }
        },
    };
};

/**
 * Contrôle client d'une pièce justificative (partials/_ui.html.twig, macro
 * document) : dès qu'un fichier est choisi, son format et sa taille sont
 * vérifiés dans le navigateur pour afficher une confirmation immédiate, sans
 * attendre l'aller-retour serveur.
 */
window.pieceJustificative = function () {
    const formatsValides = ["image/jpeg", "image/png", "application/pdf"];
    const tailleMaxOctets = 2 * 1024 * 1024;

    return {
        fichier: null,
        erreur: null,

        verifier(evenement) {
            const brut = evenement.target.files[0];

            if (!brut) {
                this.fichier = null;
                this.erreur = null;

                return;
            }

            if (!formatsValides.includes(brut.type)) {
                this.fichier = null;
                this.erreur = "Format non accepté — envoyez un JPG, un PNG ou un PDF.";

                return;
            }

            if (brut.size > tailleMaxOctets) {
                this.fichier = null;
                this.erreur = "Fichier trop volumineux — 2 Mo maximum.";

                return;
            }

            this.erreur = null;
            this.fichier = {
                nom: brut.name,
                taille: (brut.size / (1024 * 1024)).toFixed(2),
            };
        },
    };
};

/**
 * Décision rendue depuis la fiche d'un dossier : étude du conseiller VAE,
 * recevabilité du jury local. Une décision est définitive (ou fige le
 * dossier) : le premier clic contrôle le choix et la saisie, puis demande une
 * confirmation explicite ; seul le second envoie le formulaire.
 *
 * pieceExigee : pièce que l'auteur de la décision doit avoir jointe, sous la
 * forme { present: bool, cible: id de la ligne de la pièce } — le justificatif
 * d'expérience pour l'étude. La ligne de la pièce met `present` à jour quand
 * un fichier est choisi.
 *
 * Sans JavaScript, le bouton reste un simple bouton d'envoi et le serveur
 * applique les mêmes règles.
 */
window.decisionDossier = function (statutInitial = null, pieceExigee = null) {
    const defilement = () =>
        window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth";

    return {
        statut: statutInitial,
        confirmation: false,
        manque: false,
        piece: pieceExigee,
        pieceManquante: false,

        enregistrer(bouton) {
            if (!this.statut) {
                this.manque = true;

                return;
            }

            if (this.piece && !this.piece.present) {
                this.pieceManquante = true;
                const ligne = document.getElementById(this.piece.cible);

                if (ligne) {
                    ligne.scrollIntoView({ behavior: defilement(), block: "center" });
                    const champ = ligne.querySelector("input[type=file]");
                    if (champ) {
                        champ.focus({ preventScroll: true });
                    }
                }

                return;
            }

            // Champ invalide ailleurs dans le formulaire : l'envoi est demandé
            // pour que la validation (verifierAvantEnvoi) affiche les erreurs
            // et place le curseur sur le premier champ fautif.
            if (!bouton.form.checkValidity()) {
                bouton.form.requestSubmit();

                return;
            }

            this.confirmation = true;
            this.$nextTick(() => this.$refs.confirmer && this.$refs.confirmer.focus());
        },

        annuler() {
            this.confirmation = false;
            this.$nextTick(() => this.$refs.enregistrer && this.$refs.enregistrer.focus());
        },
    };
};

/**
 * Validation d'un formulaire avant sa soumission : le `novalidate` posé sur
 * chaque `<form>` désactive seulement les bulles natives du navigateur, pas
 * l'API de validation elle-même. On s'en sert ici pour afficher, en français
 * et dans le style du reste de l'interface, les mêmes erreurs (champ
 * obligatoire, format invalide…) avant que la requête ne parte, plutôt que de
 * les découvrir après coup dans la réponse du serveur.
 */
window.validationFormulaire = function () {
    return {
        init() {
            this.$el.addEventListener("input", (evenement) => this.effacerErreur(evenement.target));
            this.$el.addEventListener("change", (evenement) => this.effacerErreur(evenement.target));
        },

        verifierAvantEnvoi(evenement) {
            const formulaire = evenement.target;

            if (formulaire.checkValidity()) {
                return;
            }

            evenement.preventDefault();

            let premierInvalide = null;

            formulaire.querySelectorAll(":invalid").forEach((champ) => {
                this.afficherErreur(champ);
                premierInvalide = premierInvalide ?? champ;
            });

            if (premierInvalide) {
                const cible = this.cibleErreur(premierInvalide);
                const bouton = cible === premierInvalide ? premierInvalide : cible.querySelector("button");
                (bouton || premierInvalide).focus();
                cible.scrollIntoView({ behavior: "smooth", block: "center" });
            }
        },

        // Une <select> masquée par une liste avec recherche (x-ref « natif ») :
        // l'erreur s'affiche sous la liste entière.
        cibleErreur(champ) {
            return champ.hidden && champ.parentElement ? champ.parentElement : champ;
        },

        afficherErreur(champ) {
            this.effacerErreur(champ);

            const cible = this.cibleErreur(champ);
            const message = document.createElement("p");
            message.dataset.erreurValidation = "true";
            message.className = "mt-1.5 text-sm text-red-700";
            message.textContent = this.messagePour(champ);
            cible.insertAdjacentElement("afterend", message);
            champ.classList.add("border-red-500");
        },

        effacerErreur(champ) {
            champ.classList.remove("border-red-500");

            const suivant = this.cibleErreur(champ).nextElementSibling;
            if (suivant && suivant.dataset && suivant.dataset.erreurValidation) {
                suivant.remove();
            }
        },

        messagePour(champ) {
            const validite = champ.validity;

            if (validite.valueMissing) {
                return "Ce champ est obligatoire.";
            }
            if (validite.tooShort) {
                return `Ce champ doit contenir au moins ${champ.minLength} caractères.`;
            }
            if (validite.tooLong) {
                return `Ce champ ne peut pas dépasser ${champ.maxLength} caractères.`;
            }
            if (validite.rangeUnderflow) {
                return champ.dataset.messageMin || `La valeur minimale est ${champ.min}.`;
            }
            if (validite.rangeOverflow) {
                return `La valeur maximale est ${champ.max}.`;
            }
            if (validite.patternMismatch) {
                return champ.title || "Le format saisi n'est pas valide.";
            }
            if (validite.typeMismatch) {
                return "Le format saisi n'est pas valide.";
            }

            return "La valeur saisie n'est pas valide.";
        },
    };
};

/**
 * Cascade du formulaire de dépôt : centre → métiers → certifications, et filtre
 * des certifications selon l'expérience.
 *
 * Les trois listes se déterminent en chaîne. Le centre ouvre des métiers, et le
 * couple (centre, métier) les certifications réellement préparées : proposer
 * les diplômes du métier sans tenir compte du centre laisserait choisir une
 * certification que l'établissement n'enseigne pas. Parmi elles, seules celles
 * du type accessible s'affichent : CQP de 5 à 6 ans d'expérience, CAP à partir
 * de 7 ans — ou CQP quand le centre ne prépare aucun CAP pour ce métier (seuils
 * portés par le champ des années, règle de ProfilProfessionnel::typeRetenu).
 *
 * Partagé par les quatre écrans qui servent ce formulaire (espace candidat,
 * inscription assistée, reprise, conseiller) plutôt que recopié dans chacun.
 * Les champs sont repérés par data-depot (centre, metier, certification,
 * annees) : la liste du centre est déjà une x-ref « natif » de la liste avec
 * recherche. Le serveur revalide toujours le triplet posté et le type du
 * diplôme : ce filtrage est un confort de saisie, jamais une garantie.
 */
window.depotCandidature = function () {
    const base = window.validationFormulaire();
    const initValidation = base.init;

    // Requête en cours par liste (« metiers », « certifications »). Hors de
    // l'état Alpine : ses proxys fausseraient la comparaison d'identité qui
    // écarte une réponse dépassée.
    const requetes = { metiers: null, certifications: null };
    const indicateurs = { metiers: "chargement", certifications: "chargementCertifications" };
    // L'indicateur n'apparaît que si la réponse tarde : une réponse rapide ne
    // fait rien clignoter.
    const DELAI_INDICATEUR = 200;

    return Object.assign(base, {
        chargement: false,
        chargementCertifications: false,
        annees: "",
        // Offre du couple (centre, métier) : [{id, libelle, type}].
        certifications: [],
        certificationsChargees: false,
        formulaire: null,

        init() {
            initValidation.call(this);
            this.formulaire = this.$el;

            const annees = this.champ("annees");
            this.annees = annees ? annees.value : "";

            // Premier affichage : l'offre est déjà rendue par le serveur, avec le
            // type de chaque diplôme en data-type.
            const metier = this.champ("metier");
            const certification = this.champ("certification");
            if (certification && metier && metier.value) {
                this.certifications = Array.from(certification.options)
                    .filter((option) => option.value !== "")
                    .map((option) => ({ id: option.value, libelle: option.text, type: option.dataset.type || "" }));
                this.certificationsChargees = true;
            }
            this.afficherCertifications();
        },

        champ(nom) {
            return this.formulaire ? this.formulaire.querySelector(`[data-depot="${nom}"]`) : null;
        },

        seuil(nom) {
            const annees = this.champ("annees");

            return annees ? parseInt(annees.dataset[nom], 10) : NaN;
        },

        /** « CQP », « CAP » ou null (années non saisies ou insuffisantes). */
        typeAccessible() {
            const n = parseInt(this.annees, 10);

            if (Number.isNaN(n) || n < this.seuil("anneesCqp")) {
                return null;
            }

            return n >= this.seuil("anneesCap") ? "CAP" : "CQP";
        },

        /**
         * Type réellement proposé : celui de l'expérience, ou le CQP quand
         * l'offre chargée ne contient aucun CAP.
         */
        typeRetenu() {
            const type = this.typeAccessible();

            return type === "CAP" && this.repliCqp() ? "CQP" : type;
        },

        repliCqp() {
            return this.typeAccessible() === "CAP"
                && this.certificationsChargees
                && this.certifications.length > 0
                && !this.certifications.some((item) => item.type === "CAP");
        },

        anneesInsuffisantes() {
            const n = parseInt(this.annees, 10);

            return !Number.isNaN(n) && n < this.seuil("anneesCqp");
        },

        messageRepere() {
            const type = this.typeAccessible();

            if (this.anneesInsuffisantes()) {
                return `La VAE demande au moins ${this.seuil("anneesCqp")} ans d'expérience dans le métier.`;
            }
            if (type === null) {
                return "Saisissez les années d'expérience : le diplôme accessible s'affiche ici.";
            }

            return `Avec ${parseInt(this.annees, 10)} ans d'expérience, le diplôme accessible est un ${type}.`;
        },

        messageCertification() {
            const type = this.typeRetenu();

            if (this.repliCqp()) {
                return "Ce centre ne propose pas le CAP pour ce métier : un CQP peut être choisi.";
            }

            return type === null
                ? `Liste ouverte une fois les années d'expérience saisies (${this.seuil("anneesCqp")} ans minimum).`
                : `Seuls les ${type} sont proposés pour cette expérience.`;
        },

        /**
         * GET JSON annulable. Une nouvelle requête sur la même liste annule la
         * précédente : seule la réponse du dernier choix peut remplir la liste,
         * quel que soit l'ordre d'arrivée. Une requête dépassée rejette avec
         * une erreur « AbortError », que l'appelant ignore.
         */
        async requete(cle, url) {
            this.annuler(cle);

            const controleur = new AbortController();
            requetes[cle] = controleur;

            const minuteur = setTimeout(() => {
                if (requetes[cle] === controleur) {
                    this[indicateurs[cle]] = true;
                }
            }, DELAI_INDICATEUR);

            try {
                const reponse = await fetch(url, {
                    headers: { Accept: "application/json" },
                    signal: controleur.signal,
                });

                if (!reponse.ok) {
                    throw new Error("Chargement impossible");
                }

                const donnees = await reponse.json();

                // Annulée pendant la lecture du corps : la réponse est dépassée.
                if (controleur.signal.aborted) {
                    throw new DOMException("Requête dépassée", "AbortError");
                }

                return donnees;
            } finally {
                clearTimeout(minuteur);

                if (requetes[cle] === controleur) {
                    requetes[cle] = null;
                    this[indicateurs[cle]] = false;
                }
            }
        },

        annuler(cle) {
            if (requetes[cle]) {
                requetes[cle].abort();
                requetes[cle] = null;
            }
            this[indicateurs[cle]] = false;
        },

        async chargerMetiers() {
            const centre = this.champ("centre");
            const metier = this.champ("metier");

            if (!centre || !metier || !centre.value) {
                return;
            }

            // Changer de centre invalide aussitôt le diplôme déjà choisi (et un
            // chargement de diplômes en cours) : il appartenait à l'ancien couple.
            this.annuler("certifications");
            this.viderCertifications();

            // La liste reste en place, bloquée, plutôt que vidée : pas de saut
            // visuel ni de choix possible dans une liste en cours de chargement.
            metier.disabled = true;
            metier.replaceChildren(new Option("Chargement des métiers…", ""));

            let options;

            try {
                const metiers = await this.requete(
                    "metiers",
                    `/api/candidature/centres/${encodeURIComponent(centre.value)}/metiers`
                );

                options = metiers.length === 0
                    ? [new Option("Aucun métier ouvert dans ce centre", "")]
                    : [new Option("Sélectionnez un métier", ""), ...metiers.map((item) => {
                        const option = new Option(item.libelle, item.id);
                        option.dataset.filiere = item.filiere ?? "";

                        return option;
                    })];
            } catch (erreur) {
                // Un autre centre a été choisi entre-temps : sa requête remplira la liste.
                if (erreur.name === "AbortError") {
                    return;
                }
                options = [new Option("Chargement impossible, choisissez à nouveau le centre", "")];
            }

            metier.replaceChildren(...options);
            metier.disabled = false;
        },

        async chargerCertifications() {
            const metier = this.champ("metier");
            const certification = this.champ("certification");

            if (!certification) {
                return;
            }

            // En inscription assistée le centre est imposé : son champ n'existe
            // pas, et l'identifiant est alors porté par un attribut de données.
            const centre = this.champ("centre");
            const centreValeur = centre ? centre.value : certification.dataset.centre;

            if (!metier || !metier.value || !centreValeur) {
                this.annuler("certifications");
                this.viderCertifications();

                return;
            }

            this.certifications = [];
            this.certificationsChargees = false;
            certification.disabled = true;
            certification.replaceChildren(new Option("Chargement des diplômes…", ""));

            try {
                this.certifications = (await this.requete(
                    "certifications",
                    `/api/candidature/centres/${encodeURIComponent(centreValeur)}/metiers/${encodeURIComponent(metier.value)}/certifications`
                )).map((item) => ({
                    id: String(item.id),
                    libelle: item.libelle,
                    type: item.type || "",
                }));
                this.certificationsChargees = true;
            } catch (erreur) {
                // Un autre métier a été choisi entre-temps : sa requête remplira la liste.
                if (erreur.name === "AbortError") {
                    return;
                }
                certification.replaceChildren(new Option("Chargement impossible", ""));
                certification.disabled = false;

                return;
            }

            this.afficherCertifications();
        },

        /**
         * Réécrit la liste des diplômes : l'offre du couple (centre, métier)
         * réduite au type accessible. Le choix courant est gardé s'il reste
         * proposé.
         */
        afficherCertifications() {
            const certification = this.champ("certification");

            // Pendant un chargement, la liste appartient à la requête en cours
            // (saisie des années comprise) : elle la réécrira à son retour.
            if (!certification || requetes.certifications) {
                return;
            }

            certification.disabled = false;
            const courant = certification.value;
            const type = this.typeRetenu();
            const ajouter = (libelle, valeur = "") => certification.appendChild(new Option(libelle, valeur));

            certification.innerHTML = "";

            if (!this.certificationsChargees) {
                ajouter("Sélectionnez d'abord un métier");
            } else if (this.certifications.length === 0) {
                ajouter("Aucun diplôme proposé pour ce métier dans ce centre");
            } else if (this.anneesInsuffisantes()) {
                ajouter(`Aucun diplôme accessible avant ${this.seuil("anneesCqp")} ans d'expérience`);
            } else if (type === null) {
                ajouter("Saisissez d'abord les années d'expérience");
            } else {
                const proposees = this.certifications.filter((item) => item.type === type);

                if (proposees.length === 0) {
                    ajouter(`Aucun ${type} proposé pour ce métier dans ce centre`);
                } else {
                    ajouter("Sélectionnez le diplôme visé");
                    proposees.forEach((item) => {
                        const option = new Option(item.libelle, item.id);
                        option.dataset.type = item.type;
                        certification.appendChild(option);
                    });
                }
            }

            certification.value = Array.from(certification.options).some((option) => option.value === courant && courant !== "")
                ? courant
                : "";
        },

        viderCertifications() {
            this.certifications = [];
            this.certificationsChargees = false;
            this.afficherCertifications();
        },
    });
};

// Liste déroulante avec recherche (nationalité, indicatif téléphonique).
Alpine.data("listeDeroulante", listeDeroulante);
window.formaterNumero = formaterNumero;

window.Alpine = Alpine;
Alpine.start();

// start the Stimulus application
import "./bootstrap";
