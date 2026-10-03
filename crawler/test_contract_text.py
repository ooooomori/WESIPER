import unittest
from contract_text import contract_text,normalize_term


class ContractTextTest(unittest.TestCase):
    def test_term_style(self):
        self.assertEqual(normalize_term('2년+1년+1년'),'2+1+1년')
        self.assertEqual(normalize_term('2+1년'),'2+1년')

    def test_source_notes_removed_and_incentive_kept(self):
        source='KBO 연감 계약서 금액 기준(2019년 이후). 연장 조건 및 인센티브는 별도 확정 지급액이 아니며 원문 표기 기준. 총액: 106억원(인센티브 6억원 포함)'
        self.assertEqual(contract_text('6년',10600000000,'KRW',source),'6년 106억원 (인센티브 6억원 포함)')

    def test_wrapped_incentive_and_compensation(self):
        source='18억원(인센티브 11승 이상 1승당 1천만원, 15승 이상 1승당 2천만원 지급), 보상선수 김선수'
        result=contract_text('2년',1800000000,'KRW',source)
        self.assertIn('15승 이상 1승당 2천만원 지급',result)
        self.assertNotIn('보상선수',result)

    def test_idempotent(self):
        for source in ['연봉 20억원, 옵션 5억원','총액 69억원','106억원(인센티브 6억원 포함)']:
            result=contract_text('2+1년',2500000000,'KRW',source)
            self.assertEqual(contract_text('2+1년',2500000000,'KRW',result),result)

    def test_conditional_term_preserved(self):
        source='조건부 계약: 2023년 FA 취득 시 6년 125억원, 미취득 시 7년 최대 132억원. 군복무 기간 계약 연장. 단순 7년 확정 지급액이 아님.'
        result=contract_text('6+1년',13200000000,'KRW',source)
        self.assertIn('미취득 시 7년 최대 132억원',result)
        self.assertIn('군복무 기간 계약 연장',result)
        self.assertNotIn('확정 지급액',result)


if __name__=='__main__':unittest.main()
