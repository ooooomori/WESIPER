import { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Navbar } from "flowbite-react";
import wesiperLogo from '../assets/images/wesiper.png';

const Nav = () => {
    const location = useLocation();

    const pages = [
        {id: "kbodle", name: "KBODLE: 크보들", href: "/kbodle"},
        {id: "bingo", name: "KBO BINGO", href: "/bingo"},
        {id: "kbocandle", name: "KBO CANDLE", href: "/kbocandle"},
        {id: "contact", name: "문의하기", href:"mailto:godmascotvic@gmail.com"},
        {id:"donate", name: "후원하기", href:"https://toss.me/tulowitzki/1000"}
    ];

    useEffect(() => {
        document.title = "WESIPER - " + (pages.find(p => p.href === location.pathname )?.name ?? "웨시퍼");
    }, [location]);
    return (
        <>

            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TWQDS8MH"
            height="0" width="0" style={{display:"none",visibility:"hidden"}} title="GoogleTagManager"></iframe></noscript>

            

            <Navbar fluid className='font-family-kbo border-b'>
                
                <Link to="/">
                    <Navbar.Brand as="div">
                        <img src={wesiperLogo} className="h-8 sm:h-10 max-w-[calc(100vw-6rem)] object-contain" alt="WESIPER" />
                    </Navbar.Brand>
                </Link>
                <Navbar.Toggle />
                <Navbar.Collapse>
                    {pages.map(x => <>
                    <Link to={x.href} key={x.id}><Navbar.Link key={x.id}active={x.href === location.pathname} as="div" className='text-base '>{x.name}</Navbar.Link></Link></>)}
                </Navbar.Collapse>
            </Navbar>


        </>
    )
}

export default Nav;
