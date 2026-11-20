import { Link } from 'react-router';
import { useEffect, useState } from 'react';
import { useStorefront } from '../../context/StorefrontContext';
import { PROMOTIONS_TAB } from '../catalog/variant';
import { scrollToCatalog } from '../catalog/scrollToCatalog';
import { ArrowRight, BookOpen, CircleHelp, CreditCard, Leaf, MessageCircle, Minus, Plus, Search, Star, Truck } from 'lucide-react';
import { referenceImage } from '../../components/referenceImages';
import { shopWhatsapp } from './shop';
import content from '../content/referenceContent.json';
import CatalogPage from '../catalog/CatalogPage';

const reviews = [['Camila','Bogotá · Molido · 340 gr','Lo pedí para la casa y ya es el café de todas las mañanas. Suave, con un dulzor a panela que no necesita azúcar.'],['Diego','Medellín · Grano entero · 500 gr','Llegó rápido y muy fresco. Lo muelo para prensa francesa y el aroma llena toda la cocina.'],['Daniel','Cúcuta · Molido · 250 gr','Se siente que es café de origen. Me gusta saber que apoyo a las caficultoras del Catatumbo.'],['Paola','Bogotá · Grano entero · 2.5 Kg','Lo compramos para la oficina y a todos les encantó. Buena atención por WhatsApp y entrega puntual.']];
const facts = [['Variedad','Castillo','Resistente y aromático'],['Altura','1.800 m.s.n.m.','Maduración lenta de montaña'],['Tostión','Media-baja','Preserva la acidez brillante'],['Tipo','Excelso','Grano grande y uniforme'],['Notas','Panela y nuez','Dulzura residual marcada'],['Aroma','Floral / madera','Fragancia compleja y silvestre']];
const faqCategories = ['Pedidos y pagos','Envíos y entregas','Nuestro café','Pedidos y pagos','Pedidos y pagos','Envíos y entregas','Envíos y entregas','Pedidos y pagos','Nuestro café'];
const faqFilters = ['Todas las dudas','Pedidos y pagos','Envíos y entregas','Nuestro café'];
const normalizeFaq = value => value.toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
function FrequentlyAskedQuestions() {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState(faqFilters[0]);
    const [openQuestion, setOpenQuestion] = useState(null);
    const trimmedQuery = query.trim();
    const search = normalizeFaq(trimmedQuery);
    const results = content.faqs.map(([question, answer], index) => ({ question, answer, index })).filter(({ question, answer, index }) => (category === faqFilters[0] || faqCategories[index] === category) && normalizeFaq(`${question} ${answer}`).includes(search));
    const faqWhatsAppNumber = shopWhatsapp();
    const validNumber = Boolean(faqWhatsAppNumber);
    const message = `Hola, Café Finca Helena. Tengo una consulta:\n\n${trimmedQuery}`;
    return <section className="faq-sec" id="preguntas-frecuentes" aria-labelledby="faq-title"><div className="faq-inner">
        <div className="faq-heading"><span className="faq-eyebrow">Centro de asistencia · Café Finca Helena</span><h2 id="faq-title">Preguntas frecuentes</h2><p>Encuentra respuestas sobre nuestro café, pedidos y entregas</p></div>
        <div className="faq-panel"><label className="faq-search"><span className="sr-only">Buscar en preguntas frecuentes</span><Search aria-hidden="true" /><input type="search" value={query} maxLength={500} placeholder="Buscar por palabra clave (ej. molienda, envíos, Wompi)..." onChange={event => { setQuery(event.target.value); setOpenQuestion(null); }} /></label>
        <div className="faq-filters" role="group" aria-label="Categorías de preguntas frecuentes">{faqFilters.map(filter => <button key={filter} type="button" aria-pressed={category === filter} onClick={() => { setCategory(filter); setOpenQuestion(null); }}>{filter}</button>)}</div>
        {results.length ? <div className="faq-list">{results.map(({ question, answer, index }) => { const expanded = openQuestion === index; return <div className="faq-item" key={question}><h3><button type="button" aria-expanded={expanded} aria-controls={`faq-answer-${index}`} onClick={() => setOpenQuestion(expanded ? null : index)}><CircleHelp className="faq-question-icon" aria-hidden="true" /><span>{question}</span>{expanded ? <Minus className="faq-toggle-icon" aria-hidden="true" /> : <Plus className="faq-toggle-icon" aria-hidden="true" />}</button></h3><div id={`faq-answer-${index}`} className={`faq-answer${expanded ? ' is-open' : ''}`} aria-hidden={!expanded} inert={!expanded}><div className="faq-answer-inner"><p>{answer}</p></div></div></div>; })}</div> : <div className="faq-empty" role="status"><h3>No encontramos una respuesta para tu consulta</h3><p>Puedes comunicarte con nuestro equipo para recibir ayuda personalizada.</p>{trimmedQuery ? validNumber ? <a href={`https://wa.me/${faqWhatsAppNumber}?text=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer"><MessageCircle aria-hidden="true" /> Consultar por WhatsApp</a> : <p>El número de WhatsApp no está configurado correctamente.</p> : <button type="button" onClick={() => setCategory(faqFilters[0])}>Restablecer filtros</button>}</div>}
    </div></div></section>;
}
function Reviews() { return <section className="rv-sec" aria-labelledby="rv-title"><div className="rv-head"><span className="rv-kicker">Opiniones</span><h2 id="rv-title">Lo que dicen quienes ya lo prueban</h2></div><div className="rv-viewport"><div className="rv-track">{[...reviews,...reviews].map(([name,detail,quote],i) => <figure className="rv-card" key={i} aria-hidden={i >= reviews.length || undefined}><div className="rv-stars" aria-label="5 de 5 estrellas">{Array.from({length: 5}, (_, i) => <Star key={i} className="ui-icon" aria-hidden="true" fill="currentColor" />)}</div><blockquote>“{quote}”</blockquote><figcaption><span className="rv-av" aria-hidden="true">{name[0]}</span><span><b>{name}</b><small>{detail}</small></span></figcaption></figure>)}</div></div></section>; }
// `promotions` = the /promociones URL: the same catalogue with the virtual "Promociones" tab selected.
export default function HomePage({ promotions = false }) {
    const { selectCategory } = useStorefront();
    useEffect(() => {
        if (!promotions) return;
        selectCategory(PROMOTIONS_TAB);
        window.requestAnimationFrame(() => scrollToCatalog());
    }, [promotions]); // eslint-disable-line react-hooks/exhaustive-deps
    return <><div className="banner-wrap"><h1 className="sr-only">Café Finca Helena — Café 100% colombiano de Norte de Santander, molido y en grano</h1><img src={referenceImage('banner.webp')} width="1920" height="712" alt="Café Finca Helena — Del Catatumbo a tu taza" fetchPriority="high" /></div>
        <section className="editorial-links" aria-label="Enlaces editoriales">
            <div className="editorial-links-inner">
                <Link className="editorial-link" to="/sello-de-origen"><span className="editorial-link-icon"><Leaf aria-hidden="true" /></span><span className="editorial-link-copy"><span className="editorial-link-label">TRAZABILIDAD TOTAL</span><span className="editorial-link-title">Sello de Origen</span><span className="editorial-link-description">Conoce nuestra historia</span></span><ArrowRight className="editorial-link-arrow" aria-hidden="true" /></Link>
                <Link className="editorial-link" to="/blog"><span className="editorial-link-icon"><BookOpen aria-hidden="true" /></span><span className="editorial-link-copy"><span className="editorial-link-label">CULTURA Y PREPARACIÓN</span><span className="editorial-link-title">El Rincón del Café</span><span className="editorial-link-description">Guías y métodos</span></span><ArrowRight className="editorial-link-arrow" aria-hidden="true" /></Link>
            </div>
        </section><CatalogPage />
        <section className="seo-about" aria-labelledby="seo-about-title"><div className="seo-story"><span className="seo-eyebrow">Sello de Origen</span><h2 id="seo-about-title">Café de origen del Catatumbo, Norte de Santander</h2><p>Café Finca Helena es café 100% colombiano cultivado a 1.800 m.s.n.m. en las montañas del Norte de Santander por más de 2.700 familias caficultoras, con un papel protagonista de las mujeres del Catatumbo.</p><p>Seleccionamos el grano a mano, lo tostamos artesanalmente en pequeños lotes con una tostión media-baja y lo enviamos fresco a toda Colombia, molido o en grano entero.</p><Link className="seo-link" to="/sello-de-origen">Conoce nuestro Sello de Origen <ArrowRight className="ui-icon" aria-hidden="true" /></Link></div><div className="seo-details"><dl className="seo-facts">{facts.map(([name,value,detail]) => <div className="seo-fact" key={name}><dt>{name}</dt><dd><strong>{value}</strong><span>{detail}</span></dd></div>)}</dl><div className="seo-impact"><div><strong>Liderazgo de Mujeres Caficultoras</strong><span>Impacto social directo en la comunidad de Norte de Santander.</span></div><span className="seo-impact-badge">Origen 100% Trazable</span></div></div></section>
        <FrequentlyAskedQuestions />
        <div className="trust-strip"><div className="trust-inner">{[[Truck,'Envíos a toda Colombia','Bogotá: 24 horas hábiles. Resto del país: 3 a 5 días hábiles.'],[CreditCard,'Pago seguro en línea','Pasarela Wompi. También aceptamos transferencias.'],[Leaf,'Café 100% colombiano','Tostado artesanalmente en pequeños lotes. Frescura garantizada.']].map(([Icon,title,description]) => <div className="trust-item" key={title}><span className="ic"><Icon className="ui-icon" aria-hidden="true" /></span><div><h4>{title}</h4><p>{description}</p></div></div>)}</div></div></>;
}
