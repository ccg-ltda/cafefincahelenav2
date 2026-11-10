import banner from '../../images/reference/banner.webp';
import logo from '../../images/reference/logo.png';
import coffee125Ground from '../../images/reference/cafe-125-m.webp';
import coffee125Beans from '../../images/reference/cafe-125-t.webp';
import coffee250Ground from '../../images/reference/cafe-250-m.webp';
import coffee250Beans from '../../images/reference/cafe-250-t.webp';
import coffee340Ground from '../../images/reference/cafe-340-m.webp';
import coffee340Beans from '../../images/reference/cafe-340-t.webp';
import coffee500Ground from '../../images/reference/cafe-500-m.webp';
import coffee500Beans from '../../images/reference/cafe-500-t.webp';
import coffee25kgGround from '../../images/reference/cafe-25k-m.webp';
import coffee25kgBeans from '../../images/reference/cafe-25k-t.webp';
import blogRoast from '../../images/reference/blog-1.webp';
import blogPress from '../../images/reference/blog-2.webp';
import blogHelena from '../../images/reference/blog-3.webp';
import helenaPoster from '../../images/reference/helena-poster.webp';
import originHero from '../../images/reference/origin-hero.webp';

// Explicit imports let Vite validate every referenced image during the build.
const images = {
    'banner.webp': banner,
    'logo.png': logo,
    'cafe-125-m.webp': coffee125Ground,
    'cafe-125-t.webp': coffee125Beans,
    'cafe-250-m.webp': coffee250Ground,
    'cafe-250-t.webp': coffee250Beans,
    'cafe-340-m.webp': coffee340Ground,
    'cafe-340-t.webp': coffee340Beans,
    'cafe-500-m.webp': coffee500Ground,
    'cafe-500-t.webp': coffee500Beans,
    'cafe-25k-m.webp': coffee25kgGround,
    'cafe-25k-t.webp': coffee25kgBeans,
    'blog-1.webp': blogRoast,
    'blog-2.webp': blogPress,
    'blog-3.webp': blogHelena,
    'helena-poster.webp': helenaPoster,
    'origin-hero.webp': originHero,
};

export function referenceImage(name) {
    return images[name] ?? '';
}
