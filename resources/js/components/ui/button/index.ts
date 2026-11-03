import { cva, type VariantProps } from 'class-variance-authority';

export { default as Button } from './Button.vue';

export const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'border-2 border-black/30 bg-primary font-extrabold text-primary-foreground shadow-[0_3px_0_rgb(0_0_0/0.3)] hover:brightness-110 active:translate-y-0.5 active:shadow-[0_1px_0_rgb(0_0_0/0.3)]',
                destructive: 'border-2 border-black/30 bg-destructive font-extrabold text-destructive-foreground shadow-[0_3px_0_rgb(0_0_0/0.3)] hover:brightness-110 active:translate-y-0.5',
                outline: 'border-2 border-[var(--parchment-edge)] bg-background font-bold shadow-[0_2px_0_rgb(80_50_20/0.2)] hover:bg-accent hover:text-accent-foreground',
                secondary: 'border-2 border-[var(--parchment-edge)] bg-secondary font-bold text-secondary-foreground shadow-[0_2px_0_rgb(80_50_20/0.2)] hover:bg-secondary/80',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-9 px-4 py-2',
                sm: 'h-8 rounded-md px-3 text-xs',
                lg: 'h-10 rounded-md px-8',
                icon: 'h-9 w-9',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export type ButtonVariants = VariantProps<typeof buttonVariants>;
