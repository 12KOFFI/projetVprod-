document.addEventListener('DOMContentLoaded', function() {
    
    const style = document.createElement('style');
    style.textContent = `
        @keyframes floatGraffiti {
            0%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }
            25% { transform: translate(10px, -15px) rotate(5deg) scale(1.1); }
            50% { transform: translate(-10px, -25px) rotate(-5deg) scale(0.9); }
            75% { transform: translate(15px, -10px) rotate(3deg) scale(1.05); }
        }

        @keyframes blob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        @keyframes pulse-glow {
            0%, 100% { opacity: 0.5; filter: blur(10px); }
            50% { opacity: 0.8; filter: blur(15px); }
        }

        @keyframes rotate-slow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes countUp {
            from { transform: scale(1); }
            to { transform: scale(1); }
        }

        .animate-blob {
            animation: blob 7s infinite;
        }

        .animate-float {
            animation: float 6s ease-in-out infinite;
        }

        .animate-pulse-glow {
            animation: pulse-glow 4s ease-in-out infinite;
        }

        .animate-rotate-slow {
            animation: rotate-slow 20s linear infinite;
        }

        .animate-slide-left {
            animation: slideInLeft 1s ease-out forwards;
        }

        .animate-slide-right {
            animation: slideInRight 1s ease-out forwards;
        }

        .animate-fade-up {
            animation: fadeInUp 1s ease-out forwards;
        }

        .animation-delay-2000 {
            animation-delay: 2s;
        }

        .animation-delay-4000 {
            animation-delay: 4s;
        }

        .animation-delay-6000 {
            animation-delay: 6s;
        }

        .graffiti {
            transition: all 0.3s ease;
        }

        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s ease, transform 0.8s ease;
        }

        .reveal.revealed {
            opacity: 1;
            transform: translateY(0);
        }

        .scroll-indicator {
            position: fixed;
            top: 0;
            left: 0;
            height: 4px;
            background: linear-gradient(90deg, #0B669D, #4A9FBE);
            z-index: 1000;
            transition: width 0.1s ease;
        }
    `;
    document.head.appendChild(style);

    // ==================== SCROLL INDICATOR ====================
    const scrollIndicator = document.createElement('div');
    scrollIndicator.className = 'scroll-indicator';
    document.body.appendChild(scrollIndicator);

    window.addEventListener('scroll', function() {
        const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
        const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        const scrolled = (winScroll / height) * 100;
        scrollIndicator.style.width = scrolled + '%';
    });

    // ==================== GRAFFITIS ANIMÉS POUR COMMUNIQUÉ ====================
    const graffitiContainer = document.getElementById('graffiti-container');
    if (graffitiContainer) {
        const symbols = [
            'D', 'A', 'I', 'P', '4', '0', 'J', 'E', 'U', 'N', 'E', 'S'
        ];
        
        const colors = [
            'text-indigo-200', 'text-purple-200', 'text-pink-200', 
            'text-blue-200', 'text-amber-200', 'text-emerald-200',
            'text-rose-200', 'text-violet-200', 'text-fuchsia-200'
        ];
        
        // Créer 30 graffitis aléatoires
        for (let i = 0; i < 30; i++) {
            setTimeout(() => {
                const graffiti = document.createElement('div');
                const randomColor = colors[Math.floor(Math.random() * colors.length)];
                const randomSymbol = symbols[Math.floor(Math.random() * symbols.length)];
                const randomX = Math.random() * 100;
                const randomY = Math.random() * 100;
                const randomRotate = Math.random() * 360;
                const randomScale = 0.3 + Math.random() * 1.5;
                const randomDuration = 8 + Math.random() * 15;
                const randomDelay = Math.random() * 5;
                
                graffiti.className = `graffiti absolute text-4xl md:text-7xl opacity-10 select-none pointer-events-none ${randomColor} animate-float`;
                graffiti.style.left = randomX + '%';
                graffiti.style.top = randomY + '%';
                graffiti.style.transform = `rotate(${randomRotate}deg) scale(${randomScale})`;
                graffiti.style.animationDuration = randomDuration + 's';
                graffiti.style.animationDelay = randomDelay + 's';
                graffiti.style.filter = `blur(${Math.random() * 2}px)`;
                graffiti.textContent = randomSymbol;
                
                graffitiContainer.appendChild(graffiti);
            }, i * 50); // Échelonner la création pour éviter les ralentissements
        }
    }

    // ==================== BLOCS ANIMÉS HERO ====================
    const heroBlobs = document.querySelectorAll('.hero .animate-blob, .bg-gradient-to-br .animate-blob');
    if (heroBlobs.length > 0) {
        heroBlobs.forEach((blob, index) => {
            blob.style.animationDuration = `${7 + index * 2}s`;
        });
    }

    // ==================== REVEAL ON SCROLL ====================
    const revealElements = document.querySelectorAll('.reveal');
    if (revealElements.length > 0) {
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        revealElements.forEach(el => revealObserver.observe(el));
    }

    // ==================== PARALLAX EFFECT ====================
    const parallaxElements = document.querySelectorAll('.parallax');
    if (parallaxElements.length > 0) {
        window.addEventListener('scroll', () => {
            const scrollY = window.scrollY;
            
            parallaxElements.forEach(el => {
                const speed = el.dataset.speed || 0.5;
                const yPos = -(scrollY * speed);
                el.style.transform = `translateY(${yPos}px)`;
            });
        });
    }

    // ==================== HOVER ANIMATIONS ====================
    const cards = document.querySelectorAll('.group');
    cards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            const icon = card.querySelector('.group-hover\\:scale-110');
            if (icon) {
                icon.style.transform = 'scale(1.1)';
            }
        });
        
        card.addEventListener('mouseleave', () => {
            const icon = card.querySelector('.group-hover\\:scale-110');
            if (icon) {
                icon.style.transform = 'scale(1)';
            }
        });
    });
});