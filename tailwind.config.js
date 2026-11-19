import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['class'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{vue,js,ts,jsx,tsx}',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Nunito', ...defaultTheme.fontFamily.sans],
                pixel: ['"Pixelify Sans"', 'Nunito', ...defaultTheme.fontFamily.sans],
            },
            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
            },
            colors: {
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                card: {
                    DEFAULT: 'hsl(var(--card))',
                    foreground: 'hsl(var(--card-foreground))',
                },
                popover: {
                    DEFAULT: 'hsl(var(--popover))',
                    foreground: 'hsl(var(--popover-foreground))',
                },
                primary: {
                    DEFAULT: 'hsl(var(--primary))',
                    foreground: 'hsl(var(--primary-foreground))',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary))',
                    foreground: 'hsl(var(--secondary-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted))',
                    foreground: 'hsl(var(--muted-foreground))',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent))',
                    foreground: 'hsl(var(--accent-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive))',
                    foreground: 'hsl(var(--destructive-foreground))',
                },
                // Status-Farben: wechseln mit dem Farbschema für Farbsehschwächen (resources/css/app.css)
                good: 'hsl(var(--good) / <alpha-value>)',
                bad: 'hsl(var(--bad) / <alpha-value>)',
                stat: {
                    'hp-from': 'hsl(var(--hp-from) / <alpha-value>)',
                    'hp-to': 'hsl(var(--hp-to) / <alpha-value>)',
                    'xp-from': 'hsl(var(--xp-from) / <alpha-value>)',
                    'xp-to': 'hsl(var(--xp-to) / <alpha-value>)',
                    'mp-from': 'hsl(var(--mp-from) / <alpha-value>)',
                    'mp-to': 'hsl(var(--mp-to) / <alpha-value>)',
                },
                value: {
                    1: 'hsl(var(--value-1) / <alpha-value>)',
                    2: 'hsl(var(--value-2) / <alpha-value>)',
                    3: 'hsl(var(--value-3) / <alpha-value>)',
                    4: 'hsl(var(--value-4) / <alpha-value>)',
                    5: 'hsl(var(--value-5) / <alpha-value>)',
                    6: 'hsl(var(--value-6) / <alpha-value>)',
                    7: 'hsl(var(--value-7) / <alpha-value>)',
                },
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                chart: {
                    1: 'hsl(var(--chart-1))',
                    2: 'hsl(var(--chart-2))',
                    3: 'hsl(var(--chart-3))',
                    4: 'hsl(var(--chart-4))',
                    5: 'hsl(var(--chart-5))',
                },
                sidebar: {
                    DEFAULT: 'hsl(var(--sidebar-background))',
                    foreground: 'hsl(var(--sidebar-foreground))',
                    primary: 'hsl(var(--sidebar-primary))',
                    'primary-foreground': 'hsl(var(--sidebar-primary-foreground))',
                    accent: 'hsl(var(--sidebar-accent))',
                    'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
                    border: 'hsl(var(--sidebar-border))',
                    ring: 'hsl(var(--sidebar-ring))',
                },
            },
        },
    },
    plugins: [require('tailwindcss-animate')],
};
