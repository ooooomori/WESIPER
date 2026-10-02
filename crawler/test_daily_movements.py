"""Guard against ambiguous contract and namesake writes in the daily job."""
import unittest
from datetime import date
from daily_movement_contracts import announcement,amount,identity

class DailyMovementsTest(unittest.TestCase):
    def setUp(self):
        self.parents={62300:{'team':'SSG','name':'김민식','oldname':None,'birth':date(1989,1,1),'draft':'12 SK 2라운드','backNo':'32','pos':'포수'}}
        self.names={'김민식':{62300}}
    def test_announced_amount_and_extension(self):
        p='SSG는 16일 포수 김민식과 2+1년 총액 5억원에 FA계약을 체결했다고 발표했다.'
        r=announcement('SSG, 김민식과 FA 계약 공식 발표',[p],self.parents,self.names)
        self.assertEqual((r['contract_years'],r['contract_term'],r['contract_total_amount']),(3,'2+1년',500000000))
    def test_historical_and_proposed_contracts_rejected(self):
        for p in ['SSG는 지난해 김민식과 2년 총액 5억원에 계약을 체결했다.','SSG는 김민식과 2년 총액 5억원 계약을 제안했다.']:
            self.assertIsNone(announcement('김민식 계약',[p],self.parents,self.names))
    def test_two_players_in_title_rejected(self):
        self.names['이지영']={70000}
        self.assertIsNone(announcement('김민식과 이지영 계약 비교',['SSG는 김민식과 2년 총액 5억원에 계약을 체결했다.'],self.parents,self.names))
    def test_namesake_team_match_required(self):
        self.parents[70000]=dict(self.parents[62300],team='롯데');self.names['김민식'].add(70000)
        r=announcement('김민식 FA 계약',['SSG는 김민식과 2년 총액 5억원에 계약을 체결했다.'],self.parents,self.names)
        self.assertEqual(r['player_id'],62300)
    def test_wrong_team_not_matched(self):
        self.assertIsNone(announcement('김민식 FA 계약',['삼성은 김민식과 2년 총액 5억원에 계약을 체결했다.'],self.parents,self.names))
    def test_money_units(self):
        self.assertEqual(amount('16억 5000만원'),1650000000)
        self.assertEqual(amount('2.8억원'),280000000)
        self.assertEqual(amount('5,000만원'),50000000)

if __name__=='__main__':unittest.main()
