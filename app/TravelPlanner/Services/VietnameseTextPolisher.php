<?php

namespace App\TravelPlanner\Services;

final class VietnameseTextPolisher
{
    /**
     * @var array<string, string>
     */
    private const PHRASES = [
        '[De xuat chinh]' => '[Đề xuất chính]',
        'De xuat chinh' => 'Đề xuất chính',
        'Goc tu van' => 'Góc tư vấn',
        'he thong da danh gia' => 'hệ thống đã đánh giá',
        'he thong da danh giá' => 'hệ thống đã đánh giá',
        'danh gia' => 'đánh giá',
        'danh giá' => 'đánh giá',
        'muc phu hop' => 'mức phù hợp',
        'muc phù hợp' => 'mức phù hợp',
        'option theo' => 'lựa chọn theo',
        'option re hon' => 'lựa chọn rẻ hơn',
        'option rẻ hon' => 'lựa chọn rẻ hơn',
        'nen doi option re hon hoac tang ngân sách' => 'nên đổi lựa chọn rẻ hơn hoặc tăng ngân sách',
        'nen doi lựa chọn rẻ hơn hoac tang ngân sách' => 'nên đổi lựa chọn rẻ hơn hoặc tăng ngân sách',
        'hoac tang' => 'hoặc tăng',
        'balanced' => 'cân bằng',
        'voi yeu cau' => 'với yêu cầu',
        'voi yêu cầu' => 'với yêu cầu',
        'khong chi liet ke du lieu live' => 'không chỉ liệt kê dữ liệu live',
        'Ho so khach' => 'Hồ sơ khách',
        'Ho so khách' => 'Hồ sơ khách',
        'Phuong an nen uu tien' => 'Phương án nên ưu tiên',
        'Luu tru nen uu tien' => 'Lưu trú nên ưu tiên',
        'Trai nghiem nen xep truoc' => 'Trải nghiệm nên xếp trước',
        'Danh gia ngan sach' => 'Đánh giá ngân sách',
        'Tuyen di' => 'Tuyến đi',
        'Di chuyen goi y' => 'Di chuyển gợi ý',
        'Luu tru goi y' => 'Lưu trú gợi ý',
        'Diem tham quan noi bat' => 'Điểm tham quan nổi bật',
        'Chi phi uoc tinh' => 'Chi phí ước tính',
        'Co cau chi phi' => 'Cơ cấu chi phí',
        'di chuyen' => 'di chuyển',
        'luu tru' => 'lưu trú',
        'tham quan' => 'tham quan',
        'an uong' => 'ăn uống',
        'noi do' => 'nội đô',
        'nang trai nghiem' => 'nâng trải nghiệm',
        'du phong' => 'dự phòng',
        'ngan sach' => 'ngân sách',
        'moi nguoi moi ngay' => 'mỗi người mỗi ngày',
        'moi người moi ngày' => 'mỗi người mỗi ngày',
        'khoang' => 'khoảng',
        'nhom' => 'nhóm',
        'nguoi' => 'người',
        'ngay' => 'ngày',
        'nguoi lon' => 'người lớn',
        'tre em' => 'trẻ em',
        'khong co' => 'không có',
        'phong' => 'phòng',
        'gia tham khao' => 'giá tham khảo',
        'Gia ghe mem thap nhat' => 'Giá ghế mềm thấp nhất',
        'Tu van' => 'Tư vấn',
        'Transport advisor' => 'Tư vấn di chuyển',
        'Hotel advisor' => 'Tư vấn lưu trú',
        'Attraction advisor' => 'Tư vấn điểm tham quan',
        'round trip group cost about' => 'chi phí khứ hồi cho nhóm khoảng',
        'good value for the budget' => 'giá trị tốt so với ngân sách',
        'Chi phi rat tot so voi ngan sach' => 'Chi phí rất tốt so với ngân sách',
        'Phuong an phu hop nhat dua tren chi phi, thoi gian va muc phu hop ngan sach' => 'Phương án phù hợp nhất dựa trên chi phí, thời gian và mức phù hợp ngân sách',
        'Chi phi uoc tinh van nam trong ngan sach' => 'Chi phí ước tính vẫn nằm trong ngân sách',
        'phu hop' => 'phù hợp',
        'rat tot' => 'rất tốt',
        'so voi' => 'so với',
        'thoi gian' => 'thời gian',
        'chi phi' => 'chi phí',
        'gia' => 'giá',
        'goi y' => 'gợi ý',
        'Uu tien' => 'Ưu tiên',
        'vao buoi sang' => 'vào buổi sáng',
        'de tranh qua tai lich trinh' => 'để tránh quá tải lịch trình',
        'de tránh qua tai lich trình' => 'để tránh quá tải lịch trình',
        'Kham pha khu vuc lan can' => 'Khám phá khu vực lân cận',
        'khu vuc lan can' => 'khu vực lân cận',
        'ca phe dia phuong' => 'cà phê địa phương',
        'Giu buoi chieu nhe' => 'Giữ buổi chiều nhẹ',
        'mua sam nho' => 'mua sắm nhỏ',
        'chuan bi ve' => 'chuẩn bị về',
        'An toi' => 'Ăn tối',
        'nghi dem tai' => 'nghỉ đêm tại',
        'Ngay' => 'Ngày',
        'Nhip di chuyen' => 'Nhịp di chuyển',
        'Tao ke hoach' => 'Tạo kế hoạch',
        'mo timeline chi tiet' => 'mở timeline chi tiết',
        'AI se sap xep phuong tien, diem den va thoi gian nghi' => 'AI sẽ sắp xếp phương tiện, điểm đến và thời gian nghỉ',
        'phu hop ngan sach' => 'phù hợp ngân sách',
        'nam trong' => 'nằm trong',
        'vuot khoang' => 'vượt khoảng',
        'vuot ngan sach' => 'vượt ngân sách',
        'can can nhac' => 'cần cân nhắc',
        'nen' => 'nên',
        'doi' => 'đổi',
        'tiet kiem hon' => 'tiết kiệm hơn',
        're hon' => 'rẻ hơn',
        'du lieu' => 'dữ liệu',
        'yeu cau' => 'yêu cầu',
        'yeu thích' => 'yêu thích',
        'thich' => 'thích',
        'hien co' => 'hiện có',
        'noi bat' => 'nổi bật',
        'goi' => 'gói',
        'khach' => 'khách',
        'Khach' => 'Khách',
        'Nguon' => 'Nguồn',
        'Ket qua' => 'Kết quả',
        'tuong thich' => 'tương thích',
        'chi mang tinh' => 'chỉ mang tính',
        'chua phai' => 'chưa phải',
        'ban cuoi cung' => 'bán cuối cùng',
        'Loai xe' => 'Loại xe',
        'So ghe con lai' => 'Số ghế còn lại',
        'tong luu tru' => 'tổng lưu trú',
        'Mien phi' => 'Miễn phí',
        'tham khao' => 'tham khảo',
        'Da Nang' => 'Đà Nẵng',
        'Ha Noi' => 'Hà Nội',
        'Ho Chi Minh' => 'Hồ Chí Minh',
        'Da Lat' => 'Đà Lạt',
        'Phu Quoc' => 'Phú Quốc',
        'Nha Trang' => 'Nha Trang',
        'Ha Long' => 'Hạ Long',
    ];

    public function polishPayload(array $payload): array
    {
        return $this->walk($payload);
    }

    public function polish(string $value): string
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        $polished = strtr($value, self::PHRASES);

        return preg_replace('/\s+/', ' ', $polished) ?? $polished;
    }

    private function walk(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = $this->walk($childValue, is_string($childKey) ? $childKey : null);
            }

            return $result;
        }

        if (is_string($value) && ! $this->shouldSkip($key)) {
            return $this->polish($value);
        }

        return $value;
    }

    private function shouldSkip(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        return str_contains($key, 'url')
            || str_contains($key, 'photo')
            || str_contains($key, 'image')
            || str_contains($key, 'api_key')
            || str_contains($key, 'iata')
            || str_contains($key, 'code');
    }
}
