import Button from 'react-bootstrap/Button';
import Modal from 'react-bootstrap/Modal';
import { useEffect, useState } from 'react';


const Divs = ( {props} ) => {
  const list = [];
  for(let i=0; i<7; i++) {
    if(props.g?.includes(i)) list.push(<div key={i} className="legend-div green-div"></div>);
    else if(props.o?.includes(i)) list.push(<div key={i} className="legend-div orange-div"></div>);
    else if(props.w?.includes(i)) list.push(<div key={i} className="legend-div out-div"></div>);
    else list.push(<div key={i} className="legend-div blank-div"></div>);
  }
  return (
    <div className="flex justify-center my-2">
      {list}
    </div>
  )
}
const HelpModal = (props) => {
    const handleClose = () => {
        props.setShow(false)
    };

    return (
        <>
          <Modal show={props.show} onHide={handleClose} centered className="font-family-NaBaGo">
          <Modal.Header style={{border: "none"}} closeButton>
                <span className='font-family-kbo text-xl font-bold'>KBODLE: 크보들</span>
            </Modal.Header>
            <Modal.Body className="text-sm">
                <p className="font-family-kbo text-base">🟩 초록색</p>
                <Divs props={{g: [0,1,5]}} />
                <span>- 정답과 정보가 일치한다는 뜻이에요.</span>
                <p className="font-family-kbo text-base mt-5"> 🟨 주황색</p>
                <Divs props={{o: [0]}} />
                <span>- <strong>팀</strong>: 정답과 내 선택 선수의 소속 팀이 같은 드림/나눔 올스타에 속한다는 뜻이에요.
                <br />⚡ 드림 올스타: 두산, 롯데, 삼성, SSG, KT
                <br />⚡ 나눔 올스타: KIA, LG, 키움, 한화, NC</span>
                <Divs props={{o: [1]}} />
                <span>- <strong>포지션</strong>: 정답 선수의 서브 포지션이라는 뜻이에요.</span>
                <Divs props={{o: [2, 3]}} />
                <span>- <strong>투/타</strong>: 정답과 내 선택 선수 중 한 쪽이 양타이거나, 던지는 손은 같은데 오버핸드/언더핸드 여부만 다르다는 뜻이에요.</span>
                <Divs props={{o: [4]}} />
                <span>- <strong>나이</strong>: 정답과 내 선택 선수와의 차이가 2 이내라는 뜻이에요.</span>
                <Divs props={{o: [5]}} />
                <span>- <strong>출신고</strong>: 정답과 내 선택 선수의 고등학교 지역이 같다는 뜻이에요. (예: 서울고 ↔ 휘문고 등)<br />⚡ 국외 고등학교의 경우 모두 같은 학교로 인정되어요.</span>
                <Divs props={{o: [6]}} />
                <span>- <strong>드래프트</strong>: 정답과 내 선택 선수와 지명 방식은 같고 라운드 차이가 2 이내이거나(예: 2차 4R ↔ 2차 2R), 라운드는 같고 지명 방식이 다르다는(예: 3R ↔ 2차 3R) 뜻이에요.<br />⚡ 1차 지명 ↔ 1R의 경우 위 내용에 해당되지 않아요.</span>
                <p className="font-family-kbo text-base mt-5">⬛️ 검은색</p>
                <Divs props={{w: [0,4,6]}} />
                <span>- 위의 내용에 전부 해당되지 않는, 틀린 정보라는 뜻이에요.</span>
                
            </Modal.Body>
            <Modal.Footer style={{border: "none", display: "flex", justifyContent: "center"}}>
              <Button variant="outline-primary" className="font-family-kbo mb-3 px-5" onClick={handleClose}>도움말 닫기</Button>
            </Modal.Footer>
          </Modal>
        </>
      );
}

export default HelpModal;