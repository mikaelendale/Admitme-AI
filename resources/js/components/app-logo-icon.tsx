import type { ImgHTMLAttributes, SVGAttributes } from 'react';

export default function AppLogoIcon(
    props: ImgHTMLAttributes<HTMLImageElement> | SVGAttributes<SVGElement>,
) {
    return (
        <img
            src="/logo/logo-light.svg"
            alt="App Logo"
            className={props.className}
        />
    );
}
